<?php

namespace App\Support;

/**
 * Fonte única do catálogo inicial que todo tenant novo recebe (Plano 8, Task 8.2):
 * os cadastros de apoio (tipos de evento, de isca, de serviço, serviços,
 * produtos e unidades) e os quatro cadastros regulatórios (princípio ativo,
 * grupo químico, antídoto e registro em órgão). `CatalogoInicialSeeder` aplica
 * este catálogo a um tenant; `ProvisionamentoService` (Task 8.3) chama o mesmo
 * seeder ao criar uma empresa.
 *
 * Origem do conteúdo
 * -------------------
 * O banco de desenvolvimento consultado para esta task tem uma única empresa
 * (`company_id = 1`) e ela está longe de servir como "dado real de produção":
 * zero `EventType`, zero `BaitType`, zero cadastro regulatório, e só 5
 * `ServiceType` de fixture genérica (`database/seeders/ServiceTypeSeeder`-like,
 * sem `slug`/`description` de padrão real). Não há, portanto, tenant fundador
 * com histórico de uso para copiar. Diante disso o conteúdo abaixo foi montado
 * em duas frentes, cada uma registrada por grupo:
 *
 * - `tiposDeEvento()`, `tiposDeIsca()`, `tiposDeServico()`: prática operacional
 *   de empresas especializadas em controle de pragas (ECP) licenciadas no
 *   Brasil, nos termos da RDC nº 52/2009 da Anvisa (saneantes desinfestantes) e
 *   do vocabulário usado em relatórios de monitoramento de estação porta-isca
 *   (consumo parcial/total, isca intacta/ausente/contaminada, dispositivo
 *   danificado/extraviado, sinais de atividade) — o mesmo padrão descrito nos
 *   manuais técnicos de fabricantes como Bayer/Envu e Syngenta para inspeção de
 *   dispositivos de monitoramento de roedores e insetos.
 * - `gruposQuimicos()`, `antidotos()`, `principiosAtivos()`: classificação
 *   toxicológica de princípios ativos efetivamente registrados e comercializados
 *   para uso profissional em controle de pragas no Brasil (fenilpirazol,
 *   piretroide, neonicotinoide, cumarínico), com o antídoto/conduta clínica
 *   confirmado por busca em fichas toxicológicas e de segurança públicas (ex.:
 *   antídoto por vitamina K1 para anticoagulantes cumarínicos como brodifacoum
 *   e cumatetralila; ausência de antídoto específico — conduta sintomática e de
 *   suporte — para fenilpirazóis, piretroides e neonicotinoides).
 * - `registrosEmOrgao()`: números de Registro no Ministério da Saúde reais,
 *   copiados de ficha técnica/FISPQ pública de produtos profissionais
 *   efetivamente comercializados no Brasil (Klerat Blocos e Klerat Pellets da
 *   Syngenta, à base de brodifacoum; D'FIM Gel Baraticida, à base de fipronil;
 *   K-Othrine CE 25 da Envu/Bayer, à base de deltametrina). Cada item comenta a
 *   fonte. Isso é só um ponto de partida plausível: o registro ministerial é
 *   inerentemente específico do produto comercial que cada dedetizadora compra,
 *   então nenhum catálogo genérico substitui os registros reais que a empresa
 *   vai cadastrar conforme usa seus próprios produtos.
 *
 * Dependência entre `principiosAtivos()`, `gruposQuimicos()` e `antidotos()`
 * ---------------------------------------------------------------------------
 * O schema atual **não** tem coluna de FK ligando um princípio ativo ao grupo
 * químico ou ao antídoto: `active_ingredients`, `chemical_groups`, `antidotes`
 * e `organ_registrations` são, hoje, quatro tabelas independentes — cada uma
 * só com `id`, `company_id`, `name` (ou `record`) e timestamps (conferido em
 * `app/Models/ActiveIngredient.php` e nas migrations de criação das quatro
 * tabelas). O vínculo real entre princípio ativo, grupo químico, antídoto e
 * registro ministerial só existe no model `Product` (`active_ingredient_id`,
 * `chemical_group_id`, `antidote_id`, `organ_registration_id`), que é um
 * cadastro de produto comercial e está fora do escopo desta task.
 *
 * Por isso `principiosAtivos()` carrega `grupo_quimico` e `antidoto` como
 * metadado documental (resolvido por nome, não por id) em vez de gravar uma FK
 * que a tabela não tem. A informação fica correta e pronta para o dia em que um
 * catálogo de produtos precisar dela; `CatalogoInicialSeeder` semeia grupos
 * químicos e antídotos antes de princípios ativos nessa mesma ordem, para quem
 * vier consumir esse metadado não encontrar a referência vazia.
 *
 * `unidades()`: sem tabela própria
 * ---------------------------------
 * Não existe model nem tabela `units`/`unidades` no sistema. A unidade de
 * medida de produto é hoje uma coluna de texto livre (`unit`) nas tabelas pivô
 * `certificate_product`, `service_order_products` e `work_order_products`, e a
 * lista de opções está fixa (hardcoded) em cada formulário Vue que usa esse
 * campo (`resources/js/Pages/Certificates/Create.vue`,
 * `resources/js/Pages/WorkOrders/Create.vue`,
 * `resources/js/Components/WorkOrderTabContent.vue`, entre outros). O método
 * abaixo declara essa mesma lista em um único lugar, para documentação e para
 * servir de referência caso essas telas voltem a consultar um catálogo em vez
 * de repetir o `<option>` fixo — mas `CatalogoInicialSeeder` não persiste nada
 * a partir dele, porque não há Eloquent model correspondente.
 *
 * `servicos()` e `produtos()`: a lacuna que a Task 8.10 encontrou
 * ---------------------------------------------------------------
 * O portão do Plano 8 (`tests/Feature/OnboardingCompletoTest`) roda o fluxo
 * inteiro de ordem de serviço em um tenant recém-criado, pelos endpoints reais,
 * e ele parou duas vezes por falta de cadastro:
 *
 * 1. `WorkOrderRequest` exige `service_id` (`required|exists:services,id`), e a
 *    tela de nova OS lista `Service::where('is_active', true)`. `ServiceType`
 *    (semeado por `tiposDeServico()`) é outra tabela, hoje quase sem uso no
 *    sistema: nenhuma rota a expõe e nenhum fluxo a consome. Com o catálogo
 *    original, a empresa nova abria a primeira OS com o campo obrigatório de
 *    serviço vazio e sem nada para escolher.
 * 2. Lançar produto na OS, no certificado ou no orçamento exige um `Product` já
 *    cadastrado, e ele por sua vez depende dos quatro cadastros regulatórios.
 *    Os quatro já vinham no catálogo; o produto que os amarra, não.
 *
 * As duas listas abaixo fecham isso. `servicos()` repete o vocabulário de
 * `tiposDeServico()` de propósito (são os mesmos serviços de uma dedetizadora,
 * em duas tabelas diferentes) e nasce **sem preço**: valor é decisão comercial
 * de cada empresa, e um preço inventado no catálogo iria parar em orçamento e
 * recibo. `produtos()` espelha exatamente os quatro registros ministeriais de
 * `registrosEmOrgao()`, amarrando cada um ao princípio ativo, ao grupo químico
 * e ao antídoto correspondentes — aqui o vínculo é FK de verdade, porque
 * `products` tem as quatro colunas (`active_ingredient_id`,
 * `chemical_group_id`, `antidote_id`, `organ_registration_id`).
 */
final class CatalogoInicialDoTenant
{
    /**
     * Tipos de evento (`EventType`) usados no registro de eventos de cômodo e
     * de dispositivo durante uma ordem de serviço (`WorkOrderDeviceEvent`).
     * Cobre tanto o vocabulário genérico de visita (inspeção, limpeza) quanto o
     * vocabulário específico de monitoramento de estação porta-isca (consumo,
     * isca ausente/intacta/contaminada, dispositivo danificado, praga
     * capturada, sinais de atividade).
     *
     * @return array<int, array{name: string, description: string, color: string, is_active: bool}>
     */
    public static function tiposDeEvento(): array
    {
        return [
            ['name' => 'Inspeção', 'description' => 'Inspeção geral do cômodo ou do dispositivo, sem ocorrência específica.', 'color' => '#3b82f6', 'is_active' => true],
            ['name' => 'Consumo de isca - parcial', 'description' => 'Parte da isca do dispositivo foi consumida desde a última visita.', 'color' => '#f59e0b', 'is_active' => true],
            ['name' => 'Consumo de isca - total', 'description' => 'Toda a isca do dispositivo foi consumida, indicando atividade de praga no ponto.', 'color' => '#ef4444', 'is_active' => true],
            ['name' => 'Isca intacta', 'description' => 'Isca do dispositivo sem nenhum sinal de consumo desde a última visita.', 'color' => '#10b981', 'is_active' => true],
            ['name' => 'Isca ausente', 'description' => 'Isca não encontrada no dispositivo, possivelmente removida ou carregada.', 'color' => '#f97316', 'is_active' => true],
            ['name' => 'Isca estragada ou contaminada', 'description' => 'Isca imprópria para uso por mofo, umidade ou contaminação; substituída na visita.', 'color' => '#a16207', 'is_active' => true],
            ['name' => 'Substituição de isca', 'description' => 'A isca do dispositivo foi trocada nesta visita.', 'color' => '#6366f1', 'is_active' => true],
            ['name' => 'Dispositivo danificado', 'description' => 'Estação ou dispositivo encontrado quebrado, amassado ou violado.', 'color' => '#dc2626', 'is_active' => true],
            ['name' => 'Dispositivo extraviado', 'description' => 'Dispositivo não localizado no ponto onde foi instalado.', 'color' => '#991b1b', 'is_active' => true],
            ['name' => 'Praga capturada', 'description' => 'Praga (inseto ou roedor) encontrada capturada no dispositivo.', 'color' => '#8b5cf6', 'is_active' => true],
            ['name' => 'Sinais de atividade de praga', 'description' => 'Fezes, roeduras, pegadas ou outros indícios de presença de praga no ambiente.', 'color' => '#92400e', 'is_active' => true],
            ['name' => 'Limpeza ou higienização', 'description' => 'Higienização do dispositivo ou do ponto de instalação realizada na visita.', 'color' => '#14b8a6', 'is_active' => true],
        ];
    }

    /**
     * Tipos de isca (`BaitType`) instalados nos dispositivos de monitoramento e
     * controle. Representam o formato/formulação da isca, não a praga-alvo
     * nem uma marca comercial — por isso `brand` fica em branco: cada empresa
     * amarra a marca ao cadastrar o produto que efetivamente usa.
     *
     * @return array<int, array{name: string, brand: ?string, notes: string}>
     */
    public static function tiposDeIsca(): array
    {
        return [
            ['name' => 'Bloco parafinado', 'brand' => null, 'notes' => 'Isca rodenticida em bloco de parafina, resistente à umidade, usada em estações porta-isca externas.'],
            ['name' => 'Pellets (grãos)', 'brand' => null, 'notes' => 'Isca rodenticida granulada, alta palatabilidade, indicada para ambientes internos secos.'],
            ['name' => 'Pasta extrusada', 'brand' => null, 'notes' => 'Isca rodenticida em pasta, alta aceitação, indicada para pontos de infestação intensa.'],
            ['name' => 'Gel inseticida', 'brand' => null, 'notes' => 'Isca inseticida em gel, aplicada em frestas e rodapés para controle de baratas.'],
            ['name' => 'Isca granulada para formigas', 'brand' => null, 'notes' => 'Isca atrativa para operárias, com ação lenta para atingir a colônia.'],
            ['name' => 'Placa adesiva (cola)', 'brand' => null, 'notes' => 'Armadilha adesiva para monitoramento de insetos rastejantes e pequenos roedores, sem uso de veneno.'],
            ['name' => 'Armadilha luminosa', 'brand' => null, 'notes' => 'Dispositivo com luz UV e placa adesiva para monitoramento de insetos voadores.'],
        ];
    }

    /**
     * Tipos de serviço (`ServiceType`) oferecidos por uma dedetizadora
     * licenciada em operação no Brasil. `slug` não entra aqui: o mutator
     * `ServiceType::setNameAttribute()` já deriva o slug a partir do nome.
     *
     * @return array<int, array{name: string, description: string, active: bool, sort_order: int}>
     */
    public static function tiposDeServico(): array
    {
        return [
            ['name' => 'Desinsetização', 'description' => 'Controle de insetos em geral: baratas, formigas, moscas, mosquitos e pulgas.', 'active' => true, 'sort_order' => 1],
            ['name' => 'Desratização', 'description' => 'Controle de roedores (ratos e camundongos) com iscagem e monitoramento.', 'active' => true, 'sort_order' => 2],
            ['name' => 'Descupinização', 'description' => 'Controle de cupins subterrâneos e de madeira seca, com barreira química ou iscas.', 'active' => true, 'sort_order' => 3],
            ['name' => 'Controle de escorpiões', 'description' => 'Inspeção, vedação de frestas e aplicação de inseticida residual contra escorpiões.', 'active' => true, 'sort_order' => 4],
            ['name' => 'Controle de pombos', 'description' => 'Manejo de aves urbanas com cerca elétrica, rede de proteção ou espículas.', 'active' => true, 'sort_order' => 5],
            ['name' => 'Sanitização de caixa d\'água', 'description' => 'Limpeza, desinfecção e higienização de reservatórios de água potável.', 'active' => true, 'sort_order' => 6],
            ['name' => 'Higienização e desinfecção de ambientes', 'description' => 'Sanitização de superfícies contra vírus, bactérias e fungos.', 'active' => true, 'sort_order' => 7],
            ['name' => 'Controle de morcegos', 'description' => 'Manejo integrado de morcegos, com vedação de acessos e afugentamento.', 'active' => true, 'sort_order' => 8],
        ];
    }

    /**
     * Serviços (`Service`) que a empresa vende, e que a ordem de serviço exige.
     *
     * NÃO confundir com `tiposDeServico()`: aquele alimenta `service_types`, um
     * cadastro hoje quase sem uso; este alimenta `services`, a tabela que
     * `WorkOrderRequest` e `CertificateRequest` referenciam em `service_id`.
     * Ver o docblock da classe para o porquê de os dois existirem.
     *
     * `price` fica de fora de propósito: preço é decisão comercial de cada
     * empresa e sai impresso em orçamento e recibo. O serviço nasce ativo e sem
     * valor, e a empresa preenche o dela na primeira edição.
     *
     * @return array<int, array{name: string, description: string, category: string, is_active: bool}>
     */
    public static function servicos(): array
    {
        return [
            ['name' => 'Desinsetização', 'description' => 'Controle de insetos em geral: baratas, formigas, moscas, mosquitos e pulgas.', 'category' => 'Controle de pragas', 'is_active' => true],
            ['name' => 'Desratização', 'description' => 'Controle de roedores (ratos e camundongos) com iscagem e monitoramento.', 'category' => 'Controle de pragas', 'is_active' => true],
            ['name' => 'Descupinização', 'description' => 'Controle de cupins subterrâneos e de madeira seca, com barreira química ou iscas.', 'category' => 'Controle de pragas', 'is_active' => true],
            ['name' => 'Controle de escorpiões', 'description' => 'Inspeção, vedação de frestas e aplicação de inseticida residual contra escorpiões.', 'category' => 'Controle de pragas', 'is_active' => true],
            ['name' => 'Controle de pombos', 'description' => 'Manejo de aves urbanas com cerca elétrica, rede de proteção ou espículas.', 'category' => 'Manejo de aves', 'is_active' => true],
            ['name' => 'Sanitização de caixa d\'água', 'description' => 'Limpeza, desinfecção e higienização de reservatórios de água potável.', 'category' => 'Higienização', 'is_active' => true],
            ['name' => 'Higienização e desinfecção de ambientes', 'description' => 'Sanitização de superfícies contra vírus, bactérias e fungos.', 'category' => 'Higienização', 'is_active' => true],
            ['name' => 'Controle de morcegos', 'description' => 'Manejo integrado de morcegos, com vedação de acessos e afugentamento.', 'category' => 'Manejo de fauna', 'is_active' => true],
        ];
    }

    /**
     * Produtos (`Product`) de partida, um para cada registro ministerial de
     * `registrosEmOrgao()`.
     *
     * Ao contrário do metadado documental de `principiosAtivos()`, aqui o
     * vínculo é FK de verdade: `products` tem `active_ingredient_id`,
     * `chemical_group_id`, `antidote_id` e `organ_registration_id`. O catálogo
     * declara cada referência pela chave natural (o `name` do cadastro, ou o
     * `record` do registro em órgão) e `CatalogoInicialSeeder` resolve o id
     * dentro do tenant, depois de semear os quatro cadastros regulatórios.
     *
     * Mesmo alcance dos registros ministeriais: ponto de partida plausível para
     * a empresa emitir a primeira OS e o primeiro certificado, não a lista de
     * compras dela. Cada dedetizadora cadastra os produtos que de fato usa.
     *
     * @return array<int, array{name: string, principio_ativo: string, grupo_quimico: string, antidoto: string, registro_em_orgao: string}>
     */
    public static function produtos(): array
    {
        $semAntidotoEspecifico = 'Não há antídoto específico - tratamento sintomático e de suporte';
        $vitaminaK1 = 'Vitamina K1 (Fitomenadiona)';

        return [
            [
                'name' => 'Rodenticida em bloco parafinado (brodifacoum 0,005%)',
                'principio_ativo' => 'Brodifacoum',
                'grupo_quimico' => 'Cumarínico',
                'antidoto' => $vitaminaK1,
                'registro_em_orgao' => 'MS nº 3.0119.0024',
            ],
            [
                'name' => 'Rodenticida em pellets (brodifacoum 0,005%)',
                'principio_ativo' => 'Brodifacoum',
                'grupo_quimico' => 'Cumarínico',
                'antidoto' => $vitaminaK1,
                'registro_em_orgao' => 'MS nº 3.0119.6636',
            ],
            [
                'name' => 'Gel baraticida (fipronil 0,05%)',
                'principio_ativo' => 'Fipronil',
                'grupo_quimico' => 'Fenilpirazol',
                'antidoto' => $semAntidotoEspecifico,
                'registro_em_orgao' => 'MS nº 3.2781.0056',
            ],
            [
                'name' => 'Inseticida concentrado emulsionável (deltametrina)',
                'principio_ativo' => 'Deltametrina',
                'grupo_quimico' => 'Piretroide',
                'antidoto' => $semAntidotoEspecifico,
                'registro_em_orgao' => 'MS nº 3.3222.0014',
            ],
        ];
    }

    /**
     * Unidades de medida usadas no lançamento de produto em certificado, OS e
     * orçamento. Sem tabela própria hoje (ver docblock da classe): esta lista
     * só espelha, em um único lugar, o `<option>` já fixo no frontend.
     *
     * @return array<int, array{sigla: string, nome: string}>
     */
    public static function unidades(): array
    {
        return [
            ['sigla' => 'unidade', 'nome' => 'Unidade'],
            ['sigla' => 'kg', 'nome' => 'Quilograma (kg)'],
            ['sigla' => 'g', 'nome' => 'Grama (g)'],
            ['sigla' => 'mg', 'nome' => 'Miligrama (mg)'],
            ['sigla' => 'L', 'nome' => 'Litro (L)'],
            ['sigla' => 'mL', 'nome' => 'Mililitro (mL)'],
            ['sigla' => 'caixa', 'nome' => 'Caixa'],
            ['sigla' => 'pacote', 'nome' => 'Pacote'],
        ];
    }

    /**
     * Grupos químicos (`ChemicalGroup`) dos princípios ativos usados em
     * controle de pragas profissional.
     *
     * @return array<int, array{name: string}>
     */
    public static function gruposQuimicos(): array
    {
        return [
            ['name' => 'Piretroide'],
            ['name' => 'Fenilpirazol'],
            ['name' => 'Neonicotinoide'],
            ['name' => 'Cumarínico'],
            ['name' => 'Inorgânico'],
        ];
    }

    /**
     * Antídotos (`Antidote`) associados aos grupos químicos acima. A maioria
     * dos inseticidas usados hoje em controle urbano de pragas não tem
     * antídoto específico (conduta é sintomática e de suporte); a exceção real
     * são os rodenticidas anticoagulantes cumarínicos, cujo antídoto é
     * vitamina K1.
     *
     * @return array<int, array{name: string}>
     */
    public static function antidotos(): array
    {
        return [
            ['name' => 'Vitamina K1 (Fitomenadiona)'],
            ['name' => 'Não há antídoto específico - tratamento sintomático e de suporte'],
        ];
    }

    /**
     * Princípios ativos (`ActiveIngredient`) de uso profissional em controle
     * de pragas no Brasil. `grupo_quimico` e `antidoto` resolvem por nome, não
     * por id: são metadado documental, não FK (ver docblock da classe).
     * `CatalogoInicialSeeder` grava apenas `name` em `active_ingredients`, na
     * ordem em que este método declara os itens.
     *
     * @return array<int, array{name: string, grupo_quimico: string, antidoto: string}>
     */
    public static function principiosAtivos(): array
    {
        $semAntidotoEspecifico = 'Não há antídoto específico - tratamento sintomático e de suporte';
        $vitaminaK1 = 'Vitamina K1 (Fitomenadiona)';

        return [
            ['name' => 'Deltametrina', 'grupo_quimico' => 'Piretroide', 'antidoto' => $semAntidotoEspecifico],
            ['name' => 'Cipermetrina', 'grupo_quimico' => 'Piretroide', 'antidoto' => $semAntidotoEspecifico],
            ['name' => 'Fipronil', 'grupo_quimico' => 'Fenilpirazol', 'antidoto' => $semAntidotoEspecifico],
            ['name' => 'Imidacloprido', 'grupo_quimico' => 'Neonicotinoide', 'antidoto' => $semAntidotoEspecifico],
            ['name' => 'Brodifacoum', 'grupo_quimico' => 'Cumarínico', 'antidoto' => $vitaminaK1],
            ['name' => 'Cumatetralila', 'grupo_quimico' => 'Cumarínico', 'antidoto' => $vitaminaK1],
            ['name' => 'Ácido bórico', 'grupo_quimico' => 'Inorgânico', 'antidoto' => $semAntidotoEspecifico],
        ];
    }

    /**
     * Registros no Ministério da Saúde (`OrganRegistration`) de produtos
     * profissionais reais, comercializados no Brasil, encontrados em ficha
     * técnica/FISPQ pública de cada fabricante (fonte comentada por item). Um
     * ponto de partida plausível, não uma tentativa de cobrir todo produto que
     * uma dedetizadora usa — cada empresa cadastra o registro dos produtos que
     * de fato compra conforme os inclui no próprio estoque.
     *
     * @return array<int, array{record: string}>
     */
    public static function registrosEmOrgao(): array
    {
        return [
            // Klerat Blocos (Syngenta), brodifacoum 0,005% - bloco parafinado.
            ['record' => 'MS nº 3.0119.0024'],
            // Klerat Pellets (Syngenta), brodifacoum 0,005% - grãos.
            ['record' => 'MS nº 3.0119.6636'],
            // D'FIM Gel Baraticida, fipronil 0,05% - gel para baratas.
            ['record' => 'MS nº 3.2781.0056'],
            // K-Othrine CE 25 (Envu/Bayer), deltametrina - concentrado emulsionável.
            ['record' => 'MS nº 3.3222.0014'],
        ];
    }
}
