<?php

namespace App\Services;

use App\Models\AiDraft;
use App\Models\Certificate;
use App\Services\Ai\ParecerService;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CertificateService
{
    public function getAllCertificates(): Collection
    {
        return Certificate::with(['client', 'address', 'products', 'service'])->orderBy('created_at', 'desc')->get();
    }

    public function getCertificatesPaginated(int $perPage = 15): LengthAwarePaginator
    {
        $certificates = Certificate::with(['client', 'address', 'products', 'service'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Adicionar atributos calculados aos itens da paginação
        $certificates->getCollection()->transform(function ($certificate) {
            $certificate->append(['calculated_status', 'status_text', 'status_color']);

            return $certificate;
        });

        return $certificates;
    }

    public function findCertificate(int $id): ?Certificate
    {
        $certificate = Certificate::with(['client', 'address', 'products', 'service'])->find($id);

        if ($certificate) {
            $certificate->append(['calculated_status', 'status_text', 'status_color']);
        }

        return $certificate;
    }

    public function createCertificate(array $data): Certificate
    {
        // Extrair produtos dos dados para relacionamentos many-to-many
        $products = $data['products'] ?? [];

        // Se há work_order_id, puxar produtos e serviço automaticamente da OS
        if (! empty($data['work_order_id'])) {
            $workOrder = \App\Models\WorkOrder::with(['products', 'service'])->find($data['work_order_id']);

            if ($workOrder) {
                // Se não há produtos selecionados, usar os da OS
                if (empty($products) && $workOrder->products->isNotEmpty()) {
                    $products = $workOrder->products->map(function ($product) {
                        return [
                            'product_id' => $product->id,
                            'quantity' => $product->pivot->quantity ?? null,
                            'unit' => $product->pivot->unit ?? null,
                        ];
                    })->toArray();
                }

                // Se não há serviço selecionado, usar o da OS
                if (empty($data['service_id']) && $workOrder->service_id) {
                    $data['service_id'] = $workOrder->service_id;
                }
            }
        }

        // Remover produtos dos dados para criar o certificado
        unset($data['products']);

        // Definir valores padrão
        $data['status'] = 'active';
        if (empty($data['certificate_number'])) {
            $data['certificate_number'] = $this->generateCertificateNumber();
        }

        // Criar o certificado
        $certificate = Certificate::create($data);

        // Associar produtos (many-to-many)
        if (! empty($products)) {
            $productData = [];
            foreach ($products as $product) {
                if (! empty($product['product_id'])) {
                    $productData[$product['product_id']] = [
                        'quantity' => $product['quantity'] ?? null,
                        'unit' => $product['unit'] ?? null,
                    ];
                }
            }
            if (! empty($productData)) {
                $certificate->products()->attach($productData);
            }
        }

        $certificate = $certificate->load(['client', 'address', 'products', 'service']);
        $certificate->append(['calculated_status', 'status_text', 'status_color']);

        return $certificate;
    }

    public function updateCertificate(Certificate $certificate, array $data): bool
    {
        // Extrair produtos dos dados para relacionamentos many-to-many
        $products = $data['products'] ?? [];

        // Remover produtos dos dados para atualizar o certificado
        unset($data['products']);

        // Atualizar o certificado
        $result = $certificate->update($data);

        // Sincronizar produtos (many-to-many)
        if (isset($products)) {
            $productData = [];
            foreach ($products as $product) {
                if (! empty($product['product_id'])) {
                    $productData[$product['product_id']] = [
                        'quantity' => $product['quantity'] ?? null,
                        'unit' => $product['unit'] ?? null,
                    ];
                }
            }
            $certificate->products()->sync($productData);
        }

        return $result;
    }

    public function deleteCertificate(Certificate $certificate): bool
    {
        // Remover relacionamentos many-to-many
        $certificate->products()->detach();

        // Deletar o certificado
        return $certificate->delete();
    }

    public function searchCertificates(string $search): LengthAwarePaginator
    {
        $certificates = Certificate::with(['client', 'workOrder.address', 'products', 'service'])
            ->where(function ($query) use ($search) {
                $query->where('id', 'like', "%{$search}%")
                    ->orWhere('certificate_number', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('execution_date', 'like', "%{$search}%")
                    ->orWhere('warranty', 'like', "%{$search}%")
                    ->orWhereHas('client', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('cnpj', 'like', "%{$search}%");
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Adicionar atributos calculados aos itens da paginação
        $certificates->getCollection()->transform(function ($certificate) {
            $certificate->append(['calculated_status', 'status_text', 'status_color']);

            return $certificate;
        });

        return $certificates;
    }

    public function getCertificatesByStatus(string $status): Collection
    {
        return Certificate::with(['client', 'workOrder.address', 'products', 'service'])
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getActiveCertificates(): Collection
    {
        return $this->getCertificatesByStatus('active');
    }

    public function getExpiredCertificates(): Collection
    {
        return $this->getCertificatesByStatus('expired');
    }

    public function getCancelledCertificates(): Collection
    {
        return $this->getCertificatesByStatus('cancelled');
    }

    public function generateCertificateNumber(): string
    {
        $timestamp = time();
        $random = strtoupper(substr(md5(uniqid()), 0, 5));

        return 'CERT-'.substr($timestamp, -6).'-'.$random;
    }

    /**
     * Prepare data for PDF generation, including Base64 images.
     *
     * Guarda do Plano 25 (Task 25.3) na primeira linha: certificado com
     * parecer gerado por IA e ainda não revisado não vira documento.
     * Certificado tem valor perante fiscalização, e o responsável técnico
     * assina o que sai. Certificado sem rascunho nenhum passa direto.
     */
    public function preparePdfData(Certificate $certificate): array
    {
        ParecerService::garantirParecerRevisado(
            $certificate,
            AiDraft::TIPO_PARECER_CERTIFICADO,
            'certificado'
        );

        // Load relationships
        $certificate->load([
            'client',
            'workOrder.address.client',
            'products' => function ($query) {
                $query->withPivot(['quantity', 'unit']);
            },
            'products.activeIngredient',
            'products.chemicalGroup',
            'products.antidote',
            'products.organRegistration',
            'service',
        ]);

        $company = \App\Models\Company::current();

        return [
            'certificate' => $certificate,
            'logoSrc' => $this->convertStorageFileToBase64($company->logo_path),
            'sigOpSrc' => $this->convertStorageFileToBase64($company->signature_operational_path),
            'sigChemSrc' => $this->convertStorageFileToBase64($company->signature_chemical_path),
        ];
    }

    /**
     * Convert a stored image file to a Base64 string.
     */
    private function convertStorageFileToBase64(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $fullPath = storage_path('app/public/'.$path);

        if (! file_exists($fullPath)) {
            return null;
        }

        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        $data = file_get_contents($fullPath);

        $mime = match (strtolower($extension)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode($data);
    }

    /**
     * Certificados que pedem renovação: vencem nos próximos `$diasAntes` dias
     * ou venceram nos últimos `$diasDepois` dias. Alimenta o aviso de
     * vencimento do painel.
     *
     * Fica de fora o certificado que já foi substituído por outro do mesmo
     * cliente e endereço com garantia posterior: quem renovou não precisa
     * de aviso. Certificado sem endereço vale para qualquer endereço do
     * cliente, nos dois sentidos. A subconsulta repete o `company_id` porque escopo global não
     * alcança subconsulta escrita na mão.
     *
     * @return array{total: int, itens: \Illuminate\Support\Collection}
     */
    public function certificadosParaRenovar(int $diasAntes = 30, int $diasDepois = 30, int $limite = 10): array
    {
        $hoje = BusinessDate::hoje();

        $consulta = Certificate::query()
            ->where('certificates.status', '!=', 'cancelled')
            ->whereNotNull('certificates.warranty')
            ->whereDate('certificates.warranty', '>=', $hoje->subDays($diasDepois)->toDateString())
            ->whereDate('certificates.warranty', '<=', $hoje->addDays($diasAntes)->toDateString())
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('certificates as renovado')
                    ->whereColumn('renovado.company_id', 'certificates.company_id')
                    ->whereColumn('renovado.client_id', 'certificates.client_id')
                    // Mesmo endereço, ou um dos dois sem endereço: certificado
                    // emitido sem endereço cobre o cliente inteiro.
                    ->where(function ($q) {
                        $q->whereColumn('renovado.address_id', 'certificates.address_id')
                            ->orWhereNull('renovado.address_id')
                            ->orWhereNull('certificates.address_id');
                    })
                    ->where('renovado.status', '!=', 'cancelled')
                    ->whereColumn('renovado.warranty', '>', 'certificates.warranty');
            });

        $total = (clone $consulta)->count();

        $itens = $consulta
            ->with(['client:id,name', 'address:id,street,number,district,city'])
            ->orderBy('certificates.warranty')
            ->limit($limite)
            ->get()
            ->map(fn (Certificate $certificado) => [
                'id' => $certificado->id,
                'cliente' => $certificado->client?->name,
                'endereco' => $certificado->address
                    ? trim("{$certificado->address->street}, {$certificado->address->number} - {$certificado->address->city}", ' ,-')
                    : null,
                'garantia' => $certificado->warranty?->toDateString(),
                'dias' => $certificado->days_until_expiry,
            ]);

        return ['total' => $total, 'itens' => $itens];
    }
}
