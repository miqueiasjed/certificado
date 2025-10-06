<?php

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class CertificateService
{
    public function getAllCertificates(): Collection
    {
        return Certificate::with(['client', 'workOrder.address', 'products', 'services'])->orderBy('created_at', 'desc')->get();
    }

    public function getCertificatesPaginated(int $perPage = 15): LengthAwarePaginator
    {
        $certificates = Certificate::with(['client', 'workOrder.address', 'products', 'services'])
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
        $certificate = Certificate::with(['client', 'workOrder.address', 'products', 'services'])->find($id);

        if ($certificate) {
            $certificate->append(['calculated_status', 'status_text', 'status_color']);
        }

        return $certificate;
    }

    public function createCertificate(array $data): Certificate
    {
        // Extrair produtos e serviços dos dados para relacionamentos many-to-many
        $products = $data['products'] ?? [];
        $services = $data['services'] ?? [];

        // Remover produtos e serviços dos dados para criar o certificado
        unset($data['products'], $data['services']);

        // Definir valores padrão
        $data['status'] = 'active';
        if (empty($data['certificate_number'])) {
            $data['certificate_number'] = $this->generateCertificateNumber();
        }

        // Criar o certificado
        $certificate = Certificate::create($data);

        // Associar produtos (many-to-many)
        if (!empty($products)) {
            $productIds = collect($products)->pluck('product_id')->filter()->toArray();
            if (!empty($productIds)) {
                $certificate->products()->attach($productIds);
            }
        }

        // Associar serviços (many-to-many)
        if (!empty($services)) {
            $serviceIds = collect($services)->pluck('service_id')->filter()->toArray();
            if (!empty($serviceIds)) {
                $certificate->services()->attach($serviceIds);
            }
        }

        $certificate = $certificate->load(['client', 'workOrder.address', 'products', 'services']);
        $certificate->append(['calculated_status', 'status_text', 'status_color']);
        return $certificate;
    }

    public function updateCertificate(Certificate $certificate, array $data): bool
    {
        // Extrair produtos e serviços dos dados para relacionamentos many-to-many
        $products = $data['products'] ?? [];
        $services = $data['services'] ?? [];

        // Remover produtos e serviços dos dados para atualizar o certificado
        unset($data['products'], $data['services']);

        // Atualizar o certificado
        $result = $certificate->update($data);

        // Sincronizar produtos (many-to-many)
        if (isset($products)) {
            $productIds = collect($products)->pluck('product_id')->filter()->toArray();
            $certificate->products()->sync($productIds);
        }

        // Sincronizar serviços (many-to-many)
        if (isset($services)) {
            $serviceIds = collect($services)->pluck('service_id')->filter()->toArray();
            $certificate->services()->sync($serviceIds);
        }

        return $result;
    }

    public function deleteCertificate(Certificate $certificate): bool
    {
        // Remover relacionamentos many-to-many
        $certificate->products()->detach();
        $certificate->services()->detach();

        // Deletar o certificado
        return $certificate->delete();
    }

    public function searchCertificates(string $search): LengthAwarePaginator
    {
        $certificates = Certificate::with(['client', 'workOrder.address', 'products', 'services'])
            ->where(function($query) use ($search) {
                $query->where('id', 'like', "%{$search}%")
                      ->orWhere('certificate_number', 'like', "%{$search}%")
                      ->orWhere('status', 'like', "%{$search}%")
                      ->orWhere('notes', 'like', "%{$search}%")
                      ->orWhere('execution_date', 'like', "%{$search}%")
                      ->orWhere('warranty', 'like', "%{$search}%")
                      ->orWhereHas('client', function($q) use ($search) {
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
        return Certificate::with(['client', 'workOrder.address', 'products', 'services'])
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
        return 'CERT-' . substr($timestamp, -6) . '-' . $random;
    }
}
