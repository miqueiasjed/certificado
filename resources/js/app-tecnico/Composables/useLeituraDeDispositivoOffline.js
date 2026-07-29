// Composable de leitura OFFLINE de dispositivo (Plano 13, Task 13.6).
//
// Mesma interface reativa de `@/Composables/useLeituraDeDispositivo.js` (o
// composable online usado pelo painel principal), para ser injetado direto em
// `@/Components/LeitorDeQrCode.vue` pela prop `useLeitura`, sem precisar de
// nenhuma outra mudança no componente do leitor. A diferença central: nenhuma
// chamada de rede. Tudo sai do IndexedDB local (`db/repositorio.js`), porque em
// campo, sem sinal, é exatamente quando o técnico mais precisa ler um QR code -
// depender do endpoint `GET /api/devices/ler/{codigo}` (Task 11.4) aqui
// inutilizaria a leitura justamente onde ela importa (regra de negócio da Task
// 13.6: "leitura de QR sempre offline via IndexedDB, nunca endpoint").
//
// Lacuna conhecida e documentada (não escondida): a carga do dia
// (`AppDayLoadService::mapearOrdem()`) não traz a cadeia de substituição do
// dispositivo (`DeviceReplacement`), só o campo `situacao` do próprio registro.
// Por isso, ao ler a etiqueta de um dispositivo já substituído, este composable
// não consegue apontar para o sucessor (que só existe resolvido no servidor,
// via `DeviceScanService::sucessorNoPonto()`): mostra o aviso e oferece abrir o
// próprio registro lido mesmo assim, em vez de travar o técnico em campo.
// Fechar esta lacuna por completo exigiria trazer o histórico de substituição
// para a carga do dia, o que é mudança de backend e está fora do escopo desta
// task de frontend.
//
// Pelo mesmo motivo (carga do dia não embute isso no dispositivo), `tipoIsca`,
// `ultimoEvento`, `dispositivoLido` e `substituicao` nunca são preenchidos
// aqui - existem só para o objeto devolvido ter a mesma forma do composable
// online, e `LeitorDeQrCode.vue` não usa nenhum deles diretamente.

import { ref, computed } from 'vue';
import { obterDispositivoPorCodigoPublico, obterEndereco, obterOrdem, listarOrdens } from '../db/repositorio';

export function useLeituraDeDispositivoOffline() {
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
   * Rótulo do endereço de um dispositivo, para a mensagem de `fora_da_os` e
   * `substituido`. A tabela local `enderecos` (schema.js) não guarda o nome do
   * cliente - só a ordem carregada tem o `cliente` embutido (`AppDayLoadService`
   * embute o cliente na ordem, não no endereço) -, então esta função procura,
   * entre as ordens já carregadas neste aparelho, uma que aponte para o mesmo
   * endereço, só para exibir o nome do cliente ao lado da rua.
   */
  async function rotuloDoEndereco(addressId) {
    if (addressId === null || addressId === undefined) {
      return 'outro endereço';
    }

    const enderecoEncontrado = await obterEndereco(addressId);
    const rua = enderecoEncontrado
      ? [enderecoEncontrado.logradouro, enderecoEncontrado.numero].filter(Boolean).join(', ')
      : '';

    const ordens = await listarOrdens();
    const ordemDoEndereco = ordens.find((ordem) => Number(ordem.endereco?.id) === Number(addressId));
    const cliente = ordemDoEndereco?.cliente?.nome;

    if (cliente && rua) {
      return `${cliente} - ${rua}`;
    }

    return cliente || rua || enderecoEncontrado?.apelido || 'outro endereço';
  }

  const mensagemDaSituacao = computed(() => {
    switch (situacao.value) {
      case 'nao_encontrado':
        return 'Código não encontrado na base local deste aparelho. Sincronize a carga do dia e tente de novo.';
      case 'fora_da_os':
        return `Este dispositivo é do endereço ${endereco.value?.rotulo || 'outro endereço'}, e não do endereço desta OS.`;
      case 'substituido':
        return 'Esta etiqueta é de um dispositivo substituído. Este aparelho não tem o histórico de substituição '
          + 'offline: abra mesmo assim ou sincronize com conexão para localizar o dispositivo atual do ponto.';
      default:
        return null;
    }
  });

  // Em `substituido`, sem o sucessor resolvido localmente (ver comentário do
  // topo do arquivo), a única saída oferecida é abrir o próprio registro lido
  // mesmo assim - por isso reaproveita `podeAbrirMesmoAssim`, e não
  // `podeAbrirOAtual` (que dependeria de saber quem é "o atual").
  const podeAbrirMesmoAssim = computed(
    () => situacao.value === 'fora_da_os' || situacao.value === 'substituido',
  );
  const podeAbrirOAtual = computed(() => false);

  function abrirMesmoAssim() {
    return dispositivo.value?.codigo_publico ?? null;
  }

  function abrirOAtual() {
    return null;
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

  /**
   * Resolve o código lido, só pelo IndexedDB local. `workOrderId` é opcional:
   * sem ele a leitura é avulsa e a situação `fora_da_os` nunca aparece (mesma
   * regra do `DeviceScanService` online).
   */
  async function ler(codigo, workOrderId = null) {
    limpar();
    carregando.value = true;

    try {
      const encontrado = await obterDispositivoPorCodigoPublico(codigo);

      if (!encontrado) {
        situacao.value = 'nao_encontrado';
        return;
      }

      dispositivo.value = encontrado;

      if (encontrado.situacao === 'substituido') {
        situacao.value = 'substituido';
        endereco.value = { rotulo: await rotuloDoEndereco(encontrado.address_id) };
        return;
      }

      if (workOrderId !== null && workOrderId !== undefined && workOrderId !== '') {
        const ordem = await obterOrdem(Number(workOrderId));

        if (ordem && Number(ordem.endereco?.id) !== Number(encontrado.address_id)) {
          situacao.value = 'fora_da_os';
          endereco.value = { rotulo: await rotuloDoEndereco(encontrado.address_id) };
          return;
        }
      }

      situacao.value = 'ok';
    } catch {
      erro.value = 'Não foi possível consultar o dispositivo na base local deste aparelho.';
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
