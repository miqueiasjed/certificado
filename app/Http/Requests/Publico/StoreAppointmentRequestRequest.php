<?php

namespace App\Http\Requests\Publico;

use App\Models\AppointmentRequest;
use App\Models\Company;
use App\Services\PublicSchedulingService;
use App\Support\BusinessDate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do pedido de horário aberto na página pública (Plano 16, Task
 * 16.3): o único formulário do sistema que qualquer pessoa envia sem login.
 *
 * ## Três proteções contra robô, e o que cada uma cobre
 *
 * 1. **Campo armadilha invisível** ({@see self::CAMPO_ARMADILHA}). Campo que
 *    humano nenhum vê e nenhum navegador preenche sozinho. Preenchido, o pedido
 *    é descartado **sem erro visível**: `rules()` devolve lista vazia (nada mais
 *    é validado) e o controller responde exatamente a mesma confirmação de um
 *    envio bom, sem gravar nada. Devolver erro ensinaria o robô qual campo
 *    evitar na próxima tentativa.
 * 2. **Tempo mínimo de preenchimento** ({@see self::CAMPO_CARIMBO}). Envio em
 *    menos de {@see PublicSchedulingService::SEGUNDOS_MINIMOS_DE_PREENCHIMENTO}
 *    segundos é recusado com erro visível, porque aqui o objetivo é diferente:
 *    quem colou dados e apertou enviar rápido demais é avisado e reenvia, e o
 *    segundo envio passa. O carimbo é cifrado pelo servidor, então não dá para
 *    mandar um instante velho.
 * 3. **Limite de taxa por IP e por telefone**, que não está aqui: é middleware
 *    de rota (`throttle:agendamento-publico`, ver `routes/publico.php`), porque
 *    precisa barrar a requisição antes de qualquer processamento.
 *
 * ## Tenant e ids do corpo
 *
 * `authorize()` devolve `true` porque não existe identidade nenhuma para
 * autorizar: a rota é pública por definição. O que faz o papel de controle de
 * acesso aqui é `empresa()`: o tenant vem só do slug da URL, e slug inexistente
 * ou com agendamento desligado devolve 404 antes de qualquer regra rodar.
 *
 * O único id que vem do corpo é `service_type_id`, e ele é escopado à empresa do
 * slug na própria regra (`Rule::exists` com `company_id` e `active`), cumprindo
 * a exigência da skill de multiempresa. `PublicSchedulingService::registrar()`
 * ainda reconsulta o id dentro do tenant, como segunda barreira.
 *
 * `company_id`, `client_id`, `situacao`, `origem` e `work_order_id` não são
 * aceitos do corpo em nenhuma hipótese: quem os define é o Service.
 */
class StoreAppointmentRequestRequest extends FormRequest
{
    /**
     * Nome do campo armadilha no formulário.
     *
     * Parece campo legítimo de propósito ("site" é o que robô de formulário mais
     * gosta de preencher). A página o entrega escondido, e o nome sai na prop
     * `campo_armadilha` para o frontend não repetir a string.
     */
    public const CAMPO_ARMADILHA = 'site';

    /**
     * Nome do campo com o carimbo cifrado do instante de carregamento.
     */
    public const CAMPO_CARIMBO = 'carregado_em';

    /**
     * Empresa do slug, resolvida uma vez por requisição.
     */
    private ?Company $empresa = null;

    /**
     * Rota pública: não há usuário, papel nem permissão a conferir. Ver o
     * cabeçalho da classe para o que ocupa o lugar da autorização aqui.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Empresa dona da página, resolvida pelo slug da URL.
     *
     * Slug inexistente, ou empresa com o agendamento online desligado, lança
     * `NotFoundHttpException` e a requisição termina em 404 sem tocar em
     * validação - o mesmo 404 do GET da página.
     */
    public function empresa(): Company
    {
        return $this->empresa ??= app(PublicSchedulingService::class)
            ->empresaDoSlug($this->route('slug'));
    }

    /**
     * O pedido deve ser descartado em silêncio?
     *
     * `true` quando o campo armadilha veio preenchido. O controller responde a
     * confirmação normal e não grava nada.
     */
    public function descartarSilenciosamente(): bool
    {
        return filled($this->input(self::CAMPO_ARMADILHA));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $empresa = $this->empresa();

        if ($this->descartarSilenciosamente()) {
            // Armadilha preenchida: nada mais é validado, para o robô receber
            // byte por byte a mesma resposta de um envio bem-sucedido.
            return [];
        }

        $hoje = BusinessDate::hoje();

        return [
            'nome' => ['required', 'string', 'min:3', 'max:120'],

            // `email:filter` recusa domínio sem ponto ("fulano@empresa"), que
            // `email:rfc` aceitaria. Sem validação de DNS de propósito: ela
            // depende de rede no meio do envio do visitante.
            'email' => ['required', 'string', 'email:filter', 'max:255'],

            'telefone' => ['required', 'string', 'max:20', $this->regraDoTelefoneBrasileiro()],

            // Endereço é obrigatório porque é visita em campo: sem ele a
            // empresa não tem como avaliar se atende a região, e a confirmação
            // (Task 16.4) precisa dele para cadastrar o endereço do cliente
            // novo. Vai como texto livre - quem pede horário pela página
            // pública não tem endereço cadastrado para escolher.
            'endereco_texto' => ['required', 'string', 'min:8', 'max:1000'],

            'service_type_id' => [
                'nullable',
                'integer',
                Rule::exists('service_types', 'id')->where(function ($consulta) use ($empresa): void {
                    $consulta->where('company_id', $empresa->id)->where('active', true);
                }),
            ],

            'data_preferida' => [
                'bail',
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.$hoje->toDateString(),
                'before_or_equal:'.$hoje->addDays(PublicSchedulingService::DIAS_MAXIMOS_DE_ANTECEDENCIA)->toDateString(),
                $this->regraDoDiaOferecido($empresa),
            ],

            'periodo' => ['required', Rule::in(AppointmentRequest::PERIODOS)],

            'observacao' => ['nullable', 'string', 'max:1000'],

            self::CAMPO_CARIMBO => ['bail', 'required', 'string', $this->regraDoTempoMinimo()],

            // Declarado para o campo não passar batido em revisão, e para
            // `validated()` nunca carregá-lo adiante. Quando ele vem
            // preenchido, `rules()` nem chega aqui.
            self::CAMPO_ARMADILHA => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe seu nome.',
            'nome.min' => 'Informe seu nome completo.',
            'nome.max' => 'O nome pode ter no máximo 120 caracteres.',
            'email.required' => 'Informe seu e-mail.',
            'email.email' => 'Informe um e-mail válido, como nome@empresa.com.br.',
            'email.max' => 'O e-mail pode ter no máximo 255 caracteres.',
            'telefone.required' => 'Informe um telefone com DDD.',
            'endereco_texto.required' => 'Informe o endereço do atendimento.',
            'endereco_texto.min' => 'Informe o endereço com rua, número e bairro.',
            'endereco_texto.max' => 'O endereço pode ter no máximo 1000 caracteres.',
            'service_type_id.integer' => 'Serviço inválido.',
            'service_type_id.exists' => 'O serviço escolhido não está disponível.',
            'data_preferida.required' => 'Escolha um dia no calendário.',
            'data_preferida.date_format' => 'Escolha um dia no calendário.',
            'data_preferida.after_or_equal' => 'Escolha um dia a partir de hoje.',
            'data_preferida.before_or_equal' => 'Escolha um dia mais próximo.',
            'periodo.required' => 'Escolha entre manhã e tarde.',
            'periodo.in' => 'Escolha entre manhã e tarde.',
            'observacao.max' => 'A observação pode ter no máximo 1000 caracteres.',
            self::CAMPO_CARIMBO.'.required' => 'Recarregue a página e envie o pedido novamente.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'endereco_texto' => 'endereço',
            'service_type_id' => 'serviço',
            'data_preferida' => 'dia',
            self::CAMPO_CARIMBO => 'formulário',
        ];
    }

    /**
     * Telefone brasileiro com DDD: 10 dígitos (fixo) ou 11 (celular).
     *
     * A máscara é ignorada, o código do país é aceito e descartado, e o teste é
     * de forma, nunca de existência da linha - checar se o número existe de
     * verdade exigiria serviço externo no meio do envio.
     *
     * Celular de 10 dígitos (formato anterior ao nono dígito) é recusado de
     * propósito: número assim não completa chamada desde 2016, e a empresa
     * precisa conseguir ligar de volta, que é o único canal garantido para
     * confirmar o pedido.
     */
    private function regraDoTelefoneBrasileiro(): Closure
    {
        return static function (string $atributo, mixed $valor, Closure $falhar): void {
            $digitos = preg_replace('/\D+/', '', (string) $valor) ?? '';

            if (strlen($digitos) > 11 && str_starts_with($digitos, '55')) {
                $digitos = substr($digitos, 2);
            }

            $tamanho = strlen($digitos);

            if ($tamanho !== 10 && $tamanho !== 11) {
                $falhar('Informe o telefone com DDD, como (11) 99999-9999.');

                return;
            }

            if ((int) substr($digitos, 0, 2) < 11) {
                $falhar('O DDD informado não existe.');

                return;
            }

            $primeiro = (int) $digitos[2];

            if ($tamanho === 11 && $primeiro !== 9) {
                $falhar('Celular com DDD tem 11 dígitos e começa com 9.');

                return;
            }

            if ($tamanho === 10 && ($primeiro < 2 || $primeiro > 5)) {
                $falhar('Confira o telefone. Se for celular, informe os 11 dígitos com o 9 na frente.');
            }
        };
    }

    /**
     * O dia escolhido, no período escolhido, é um dos que a grade oferece?
     *
     * Sem isto a tabela aceitaria pedido para domingo, para daqui a dois anos ou
     * para um período que já lotou, tudo por POST direto no endpoint sem passar
     * pelo calendário da página. Isso não substitui a reconferência da
     * disponibilidade na confirmação (Task 16.4), que é a decisão de verdade:
     * entre o pedido e a resposta a agenda muda.
     *
     * Nada é revelado além do que a própria grade já é pública para mostrar
     * (`GET /agendar/{slug}/grade`): a mensagem diz que o dia não está
     * disponível, nunca quantas vagas faltam ou por quê.
     */
    private function regraDoDiaOferecido(Company $empresa): Closure
    {
        // Sem `static`: a regra lê `periodo` do próprio corpo da requisição.
        return function (string $atributo, mixed $valor, Closure $falhar) use ($empresa): void {
            $periodo = $this->input('periodo');

            if (! in_array($periodo, AppointmentRequest::PERIODOS, true)) {
                // A regra de `periodo` já reclama por si. Reclamar aqui também
                // daria dois erros para o mesmo engano.
                return;
            }

            $dia = (string) $valor;
            $grade = app(PublicSchedulingService::class)->gradeDoMes($empresa, substr($dia, 0, 7));

            foreach ($grade as $oferecido) {
                if ($oferecido['data'] === $dia && ($oferecido[$periodo]['disponivel'] ?? false) === true) {
                    return;
                }
            }

            $falhar('Esse dia e período não estão mais disponíveis. Escolha outra opção no calendário.');
        };
    }

    /**
     * Tempo mínimo entre carregar o formulário e enviá-lo.
     *
     * Carimbo ausente, adulterado ou ilegível cai na mesma mensagem de
     * "recarregue a página": para quem está de boa-fé é o que resolve (a página
     * entrega um carimbo novo), e para o robô que faz POST direto não há
     * informação nenhuma no texto.
     */
    private function regraDoTempoMinimo(): Closure
    {
        return static function (string $atributo, mixed $valor, Closure $falhar): void {
            $segundos = app(PublicSchedulingService::class)->segundosDesdeOCarregamento($valor);

            if ($segundos === null) {
                $falhar('Recarregue a página e envie o pedido novamente.');

                return;
            }

            if ($segundos < PublicSchedulingService::SEGUNDOS_MINIMOS_DE_PREENCHIMENTO) {
                $falhar('Envio rápido demais. Confira os dados e envie novamente.');
            }
        };
    }
}
