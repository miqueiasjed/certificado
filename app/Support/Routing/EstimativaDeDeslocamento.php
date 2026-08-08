<?php

namespace App\Support\Routing;

/**
 * Resultado de `EstimadorDeDeslocamento::estimar()` (Plano 22, Task 22.3):
 * distância e duração entre duas coordenadas.
 *
 * `estimativa` vem sempre `true`, sem construtor que permita `false` fora
 * daqui de propósito nenhum caminho especial: esta entrega decide, de
 * propósito, não chamar serviço externo de rota (ver o cabeçalho de
 * `EstimadorDeDeslocamento`), então todo valor que sai daqui é aproximado por
 * definição. O campo existe para que quem exibe o valor (tela, aplicativo,
 * notificação) nunca precise adivinhar isso a partir do tipo, e sempre marque
 * a hora de chegada como prevista, nunca como exata - prometer hora exata em
 * serviço de campo é o tipo de promessa que gera reclamação quando o trânsito
 * não coopera.
 */
final readonly class EstimativaDeDeslocamento
{
    public function __construct(
        public float $distanciaKm,
        public int $duracaoMin,
        public bool $estimativa = true,
    ) {}
}
