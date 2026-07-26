import { ref } from 'vue';
import { hojeISO, inputDateTimeParaUtc } from '@/utils/formatDate';
import { reagendarOrdem } from '@/utils/agendaApi';

// Reagendamento por arrastar e soltar, com a API nativa de drag-and-drop do HTML5
// (draggable / dragstart / dragover / drop). Nenhuma biblioteca nova, mesma decisão
// da Task 10.4 para a grade do calendário.
//
// Importante: isto NÃO funciona bem em tela de toque (o HTML5 Drag and Drop é feito
// para mouse; em celular/tablet o `dragstart` de um elemento `draggable` raramente
// dispara de forma confiável). Por decisão da Task 10.6, este composable não tenta
// emular arrasto por toque. Em telas de toque, a ação "Reagendar" do painel lateral
// (Task 10.5, Agenda/Index.vue) continua sendo o caminho de reagendamento: ela já
// funciona em qualquer dispositivo porque abre com o clique/toque no cartão, que
// `CartaoDeVisita.vue` já emite independentemente de arrasto.

// Ordens nestas situações não são arrastáveis: a regra também vive no backend
// (WorkOrderService/ReagendamentoRequest), aqui ela só evita a tentativa.
const STATUS_NAO_ARRASTAVEIS = ['completed', 'cancelled'];

function calcularDuracaoMinutos(horaInicio, horaFim) {
  if (!horaInicio || !horaFim) return null;

  const [h1, m1] = horaInicio.split(':').map(Number);
  const [h2, m2] = horaFim.split(':').map(Number);

  if ([h1, m1, h2, m2].some((numero) => Number.isNaN(numero))) return null;

  const minutos = h2 * 60 + m2 - (h1 * 60 + m1);

  return minutos > 0 ? minutos : null;
}

const MINUTOS_POR_DIA = 24 * 60;

// Soma minutos a uma hora "HH:mm" sem passar da meia-noite: o backend recusa horário
// que caia fora do dia agendado (ReagendamentoRequest), então o fim de uma visita
// arrastada perto da virada do dia é limitado a 23:59 do mesmo dia, em vez de vazar
// para o dia seguinte.
function somarMinutosMesmoDia(horaBase, minutos) {
  const [hora, minuto] = horaBase.split(':').map(Number);
  const total = Math.min(hora * 60 + minuto + minutos, MINUTOS_POR_DIA - 1);
  const horaFinal = Math.floor(total / 60);
  const minutoFinal = total % 60;

  return `${String(horaFinal).padStart(2, '0')}:${String(minutoFinal).padStart(2, '0')}`;
}

/**
 * Chave textual de um alvo de arrasto, usada para saber qual célula está sob o
 * cursor (destaque visual) e para comparar alvos entre si.
 *
 * `alvo.tipo`:
 * - `'dia'`: célula de dia da visão de mês, muda a data e preserva o horário atual.
 * - `'semHorario'`: faixa "Sem horário" das visões de dia/semana, limpa o horário.
 * - `'faixa'`: faixa de hora das visões de dia/semana, muda data e horário.
 */
function chaveDoAlvo(alvo) {
  if (alvo.tipo === 'faixa') return `faixa:${alvo.data}:${alvo.hora}`;

  return `${alvo.tipo}:${alvo.data}`;
}

// Alvo em data passada não é reagendável pelo arrasto: mover uma visita para um dia
// que já passou não faz sentido de negócio, mesmo que o backend não recuse.
function alvoValido(alvo) {
  return Boolean(alvo?.data) && alvo.data >= hojeISO();
}

/**
 * Horário resultante da visita no alvo, conforme o tipo de célula (ver `chaveDoAlvo`).
 */
function calcularNovoHorario(visita, alvo) {
  if (alvo.tipo === 'dia') {
    return { horaInicio: visita.hora_inicio || null, horaFim: visita.hora_fim || null };
  }

  if (alvo.tipo === 'semHorario') {
    return { horaInicio: null, horaFim: null };
  }

  const duracaoMinutos = calcularDuracaoMinutos(visita.hora_inicio, visita.hora_fim);
  const horaInicio = `${String(alvo.hora).padStart(2, '0')}:00`;
  const horaFim = duracaoMinutos !== null ? somarMinutosMesmoDia(horaInicio, duracaoMinutos) : null;

  return { horaInicio, horaFim };
}

function construirPayload(data, horaInicio, horaFim) {
  return {
    scheduled_date: data,
    start_time: horaInicio ? inputDateTimeParaUtc(`${data}T${horaInicio}`) : null,
    end_time: horaFim ? inputDateTimeParaUtc(`${data}T${horaFim}`) : null,
  };
}

/**
 * Estado e handlers do arrastar-e-soltar de visitas no calendário.
 *
 * @param {{
 *   onConflito?: (dados: { message: string, conflitos: any[] }, visita: object) => void,
 *   onAviso?: (mensagem: string|null) => void,
 *   onErro?: (mensagem: string) => void,
 * }} opcoes
 */
export function useArrastarVisita({ onConflito, onAviso, onErro } = {}) {
  // Visita atualmente sendo arrastada (null fora de um arrasto).
  const visitaArrastando = ref(null);

  // Chave (ver `chaveDoAlvo`) da célula sob o cursor, para destacar o alvo válido.
  const alvoAtivo = ref(null);

  // Reagendamento do arrasto em andamento (aguardando o backend responder).
  const reagendando = ref(false);

  function podeArrastar(visita) {
    return !STATUS_NAO_ARRASTAVEIS.includes(visita?.status);
  }

  function aoIniciarArrasto(evento, visita) {
    // Defesa: com `:draggable="podeArrastar(visita)"` no cartão, o navegador nem
    // dispara `dragstart` para uma visita concluída/cancelada. Isto só cobre o caso
    // de o atributo não ter sido aplicado.
    if (!podeArrastar(visita)) {
      evento.preventDefault();

      return;
    }

    visitaArrastando.value = visita;
    evento.dataTransfer.effectAllowed = 'move';
    // Firefox exige `setData` para permitir o arrasto, mesmo sem usar o valor no drop.
    evento.dataTransfer.setData('text/plain', String(visita.id));
  }

  function aoTerminarArrasto() {
    visitaArrastando.value = null;
    alvoAtivo.value = null;
  }

  function aoArrastarSobre(evento, alvo) {
    if (!visitaArrastando.value || !alvoValido(alvo)) {
      // Sem `preventDefault()`, o navegador recusa o drop aqui (cursor de "não
      // permitido"): é assim que uma data passada vira alvo inválido sem exigir
      // nenhum tratamento especial no `drop`.
      return;
    }

    evento.preventDefault();
    evento.dataTransfer.dropEffect = 'move';
    alvoAtivo.value = chaveDoAlvo(alvo);
  }

  function aoSairDoAlvo(alvo) {
    if (alvoAtivo.value === chaveDoAlvo(alvo)) {
      alvoAtivo.value = null;
    }
  }

  function ehAlvoAtivo(alvo) {
    return alvoAtivo.value === chaveDoAlvo(alvo);
  }

  async function aoSoltar(evento, alvo) {
    evento.preventDefault();

    const visita = visitaArrastando.value;
    visitaArrastando.value = null;
    alvoAtivo.value = null;

    if (!visita || !podeArrastar(visita) || !alvoValido(alvo)) return;

    const { horaInicio, horaFim } = calcularNovoHorario(visita, alvo);

    const semMudanca =
      visita.data === alvo.data &&
      (visita.hora_inicio || null) === horaInicio &&
      (visita.hora_fim || null) === horaFim;

    // Soltou em cima do próprio lugar: nem chama o backend.
    if (semMudanca) return;

    const original = { data: visita.data, hora_inicio: visita.hora_inicio, hora_fim: visita.hora_fim };

    // Atualização otimista: a visita já se move na tela, antes da resposta do
    // backend. `visita` é o mesmo objeto reativo que está no array de visitas do
    // calendário, então mutar os campos aqui já move o cartão na grade.
    visita.data = alvo.data;
    visita.hora_inicio = horaInicio;
    visita.hora_fim = horaFim;

    reagendando.value = true;

    try {
      const { ok, dados } = await reagendarOrdem(visita.id, construirPayload(alvo.data, horaInicio, horaFim));

      if (!ok) {
        // Backend recusou: desfaz o arrasto, a visita volta exatamente para onde
        // estava.
        Object.assign(visita, original);

        if (dados?.conflito) {
          onConflito?.(dados, visita);
        } else {
          onErro?.(dados?.message || 'Não foi possível reagendar a visita.');
        }

        return;
      }

      // Sincroniza com o formato definitivo devolvido pelo backend (ex.: status,
      // técnico, textos derivados), em vez de confiar só na atualização otimista.
      Object.assign(visita, dados.ordem);

      onAviso?.(dados.tem_aviso ? dados.mensagem_aviso : null);
    } catch (erro) {
      // Falha de rede (ou resposta que não veio em JSON): mesma garantia de
      // desfazer. Sem ela a tela mostraria um estado que não existe no banco.
      Object.assign(visita, original);
      onErro?.('Não foi possível reagendar a visita. Verifique sua conexão e tente novamente.');
    } finally {
      reagendando.value = false;
    }
  }

  return {
    visitaArrastando,
    alvoAtivo,
    reagendando,
    podeArrastar,
    ehAlvoAtivo,
    aoIniciarArrasto,
    aoTerminarArrasto,
    aoArrastarSobre,
    aoSairDoAlvo,
    aoSoltar,
  };
}

export default useArrastarVisita;
