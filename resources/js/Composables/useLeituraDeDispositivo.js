import { ref, computed } from 'vue';
import axios from 'axios';

/**
 * Resolve o código de um dispositivo lido pela câmera ou digitado à mão.
 *
 * Chama `GET /api/devices/ler/{codigo}` (rota `api.devices.ler`), com
 * `work_order_id` como query param quando a leitura acontece dentro de uma
 * ordem de serviço em execução. O endpoint (`DeviceScanService`) só lê:
 * nenhuma chamada daqui grava nada no banco, e quem decide o que fazer com o
 * resultado é sempre quem consome este composable — por exemplo,
 * `LeitorDeQrCode.vue` decide mostrar o aviso e esperar uma decisão, ou uma
 * tela que já tem o `work_order_id` decide navegar direto na situação `ok`.
 *
 * As quatro situações possíveis (`nao_encontrado`, `fora_da_os`,
 * `substituido`, `ok`) chegam em `situacao`. `mensagemDaSituacao` traduz as
 * três primeiras para o texto que vai na tela; `ok` não tem mensagem própria
 * porque uma leitura sem ressalva segue direto para quem chamou.
 */
export function useLeituraDeDispositivo() {
  const situacao = ref(null);
  const dispositivo = ref(null);
  const endereco = ref(null);
  const tipoIsca = ref(null);
  const ultimoEvento = ref(null);
  const dispositivoLido = ref(null);
  const substituicao = ref(null);
  const enderecoDaOrdem = ref(null);
  const carregando = ref(false);
  const erro = ref(null);

  /**
   * Descrição curta do endereço para a mensagem de `fora_da_os`: o técnico
   * reconhece o ponto pelo nome do cliente, não pelo id do endereço (mesmo
   * critério do comentário em `DeviceScanService::dadosDoDispositivo`).
   */
  function rotuloDoEndereco(enderecoLido) {
    if (!enderecoLido) {
      return 'outro endereço';
    }

    const cliente = enderecoLido.client?.name;
    const rua = [enderecoLido.street, enderecoLido.number].filter(Boolean).join(', ');

    if (cliente && rua) {
      return `${cliente} - ${rua}`;
    }

    return cliente || rua || 'outro endereço';
  }

  /**
   * Descrição curta do dispositivo para a mensagem de `substituido`.
   */
  function rotuloDoDispositivo(dispositivoAtual) {
    if (!dispositivoAtual) {
      return 'outro dispositivo';
    }

    if (dispositivoAtual.label && dispositivoAtual.number) {
      return `${dispositivoAtual.label} (nº ${dispositivoAtual.number})`;
    }

    return dispositivoAtual.label
      || dispositivoAtual.number
      || dispositivoAtual.codigo_publico
      || 'outro dispositivo';
  }

  const mensagemDaSituacao = computed(() => {
    switch (situacao.value) {
      case 'nao_encontrado':
        return 'Código não encontrado nesta empresa.';
      case 'fora_da_os':
        return `Este dispositivo é do endereço ${rotuloDoEndereco(endereco.value)}, e não do endereço desta OS.`;
      case 'substituido':
        return `Esta etiqueta é de um dispositivo substituído. O ponto usa agora ${rotuloDoDispositivo(dispositivo.value)}.`;
      default:
        return null;
    }
  });

  const podeAbrirMesmoAssim = computed(() => situacao.value === 'fora_da_os');
  const podeAbrirOAtual = computed(() => situacao.value === 'substituido');

  /**
   * Código do dispositivo a abrir quando o técnico decide seguir mesmo com o
   * aviso de `fora_da_os`.
   *
   * Em `fora_da_os` o dispositivo lido é o próprio dispositivo a abrir; só o
   * endereço diverge do endereço da ordem. `abrirOAtual()` abaixo devolve o
   * mesmo campo, porque em `substituido` o backend já troca `dispositivo`
   * pelo ATUAL do ponto, e não pelo que a etiqueta velha identificou (ver
   * `DeviceScanService::respostaSubstituido`). As duas funções existem
   * separadas para deixar explícito, em quem chama, qual decisão está sendo
   * tomada — não para calcular coisas diferentes.
   */
  function abrirMesmoAssim() {
    return dispositivo.value?.codigo_publico ?? null;
  }

  /**
   * Código do dispositivo a abrir quando o técnico decide seguir com o
   * dispositivo que substituiu a etiqueta lida. Ver o comentário de
   * `abrirMesmoAssim()`.
   */
  function abrirOAtual() {
    return dispositivo.value?.codigo_publico ?? null;
  }

  function limpar() {
    situacao.value = null;
    dispositivo.value = null;
    endereco.value = null;
    tipoIsca.value = null;
    ultimoEvento.value = null;
    dispositivoLido.value = null;
    substituicao.value = null;
    enderecoDaOrdem.value = null;
    erro.value = null;
  }

  function aplicarResposta(dados) {
    situacao.value = dados?.situacao ?? null;
    dispositivo.value = dados?.dispositivo ?? null;
    endereco.value = dados?.endereco ?? null;
    tipoIsca.value = dados?.tipo_isca ?? null;
    ultimoEvento.value = dados?.ultimo_evento ?? null;
    dispositivoLido.value = dados?.dispositivo_lido ?? null;
    substituicao.value = dados?.substituicao ?? null;
    enderecoDaOrdem.value = dados?.endereco_da_ordem ?? null;
  }

  /**
   * Traduz uma falha da requisição (e não uma situação prevista do endpoint)
   * para uma frase que a tela pode mostrar.
   *
   * 403 e 404 vêm da ordem de serviço informada: técnico sem vínculo com ela
   * (`WorkOrderAccessService`, dentro do Service) ou ordem inexistente/de
   * outra empresa (`findOrFail` em `DeviceScanService::ordemDaLeitura`).
   * Qualquer outra coisa é tratada como falha de rede.
   */
  function mensagemDeErroHttp(erroCapturado) {
    const status = erroCapturado?.response?.status;

    if (status === 403) {
      return 'Você não tem acesso a esta ordem de serviço.';
    }

    if (status === 404) {
      return 'Ordem de serviço não encontrada.';
    }

    if (status === 422) {
      return erroCapturado?.response?.data?.message || 'A ordem de serviço informada é inválida.';
    }

    return 'Não foi possível consultar o dispositivo agora. Verifique a conexão e tente novamente.';
  }

  /**
   * Resolve o código lido. `workOrderId` é opcional: sem ele a leitura é
   * avulsa e a situação `fora_da_os` nunca aparece (mesma regra do Service).
   *
   * Sem escrita no banco: só consulta e devolve, via os refs reativos acima.
   */
  async function ler(codigo, workOrderId = null) {
    limpar();
    carregando.value = true;

    try {
      const resposta = await axios.get(route('api.devices.ler', { codigo }), {
        params: workOrderId ? { work_order_id: workOrderId } : {},
      });

      aplicarResposta(resposta.data);
    } catch (erroCapturado) {
      erro.value = mensagemDeErroHttp(erroCapturado);
    } finally {
      carregando.value = false;
    }
  }

  return {
    situacao,
    dispositivo,
    endereco,
    tipoIsca,
    ultimoEvento,
    dispositivoLido,
    substituicao,
    enderecoDaOrdem,
    carregando,
    erro,
    mensagemDaSituacao,
    podeAbrirMesmoAssim,
    podeAbrirOAtual,
    abrirMesmoAssim,
    abrirOAtual,
    ler,
    limpar,
  };
}
