# Tasks do Plano 12 - App do técnico: fundação offline

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 12.1 | Migrations e models de sincronização e conflito | backend-estrutura | ✅ | média |
| 12.2 | Autenticação do aplicativo do técnico | backend-endpoint | ✅ | média |
| 12.3 | Endpoint de carga do dia do técnico | backend-endpoint | ✅ | alta |
| 12.4 | Aplicação idempotente e detecção de conflito | backend-logica | ✅ | alta |
| 12.5 | Endpoint de sincronização em lote e envio de foto | backend-endpoint | ✅ | média |
| 12.6 | PWA instalável com service worker e casca offline | config | ✅ | média |
| 12.7 | Armazenamento local em IndexedDB | frontend-componente | ✅ | alta |
| 12.8 | Fila de sincronização com repetição e ordem | frontend-componente | ✅ | alta |
| 12.9 | Login e lista do dia do aplicativo | frontend-pagina | ✅ | alta |
| 12.10 | Indicador de sincronização e resolução de conflito | frontend-componente | ✅ | alta |
| 12.11 | Captura e compressão de foto no aparelho | frontend-componente | ✅ | alta |
| 12.12 | Testes do ciclo offline completo | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             12.1
Lote 2 (paralelo):  12.2  |  12.6
Lote 3 (paralelo):  12.3  |  12.4
Lote 4:             12.5                 (toca routes/api.php depois de 12.2 e 12.3)
Lote 5:             12.7
Lote 6:             12.8
Lote 7 (paralelo):  12.9  |  12.11
Lote 8:             12.10
Lote 9:             12.12
```

## Dependências internas

- 12.3, 12.4 e 12.5 dependem de 12.1 e de 12.2 (token e escopo)
- 12.5 depende de 12.4 (é o transporte do Service)
- 12.7 depende de 12.3 (o esquema local espelha a carga) e de 12.6 (ponto de entrada)
- 12.8 depende de 12.7 e de 12.5
- 12.9 depende de 12.7 e de 12.2
- 12.10 depende de 12.8
- 12.11 depende de 12.8
- 12.12 depende de 12.3, 12.4 e 12.5

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/api.php` | 12.2, 12.3, 12.5 | Nesta ordem, uma por vez |
| `resources/js/app-tecnico/db/schema.js` | 12.7, 12.8, 12.11 | 12.7 cria; 12.8 e 12.11 acrescentam tabela com versão nova do Dexie |
| `vite.config.js` | 12.6 | Task única |
| `app/Support/DominioMultiempresa.php` | 12.1 | Acrescenta 2 tabelas |

## Decisões registradas

- **O uuid da operação nasce no celular.** É a única forma de reconhecer o reenvio de algo cuja resposta se perdeu na rede. Unique no banco fecha a idempotência.
- **Service worker guarda a casca; IndexedDB guarda o dado.** Cachear resposta de API criaria uma segunda fonte da verdade e mostraria dado velho sem o técnico saber.
- **Atualização do PWA é oferecida, não aplicada sozinha.** Atualizar no meio de uma OS descartaria o estado da tela.
- **Token de 30 dias renovado a cada sincronização.** Técnico ativo nunca cai; celular perdido expira sozinho.
- **Nenhum dado financeiro vai para o celular.** Aparelho em campo se perde, e o técnico não precisa de valor, custo ou margem para executar.
- **Foto viaja em requisição própria, comprimida no aparelho.** Base64 no lote aumentaria um terço do tamanho e faria o lote inteiro falhar por uma imagem.
- **Conflito nunca é resolvido automaticamente.** O valor do técnico fica guardado até alguém decidir, e a decisão fica registrada.

## Roteiro manual de aceite (obrigatório antes do deploy)

Em celular Android real, por HTTPS:

1. Instalar o aplicativo na tela inicial e logar.
2. Carregar o dia com rede e conferir a lista.
3. Ativar o modo avião. Fechar o aplicativo. Reiniciar o aparelho.
4. Abrir o aplicativo ainda em modo avião: precisa abrir logado e mostrar as OS.
5. Registrar 10 operações e tirar 5 fotos, tudo offline.
6. Fechar o aplicativo no meio, reabrir, conferir que a fila continua com 15 itens.
7. Religar a rede e acompanhar o envio: 15 itens saem, nada duplica, nada some.
8. Repetir com a rede oscilando (ligar e desligar durante o envio).

## Ordem de aplicação em produção

1. Deploy do backend (12.1 a 12.5). Nada muda para o cliente atual: são rotas novas em `/api/app`.
2. Deploy do aplicativo (12.6 a 12.11) com acesso liberado a um técnico de teste primeiro.
3. Liberar para os demais técnicos depois de uma semana de uso real do primeiro.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 12 porque a fila, o armazenamento local, o indicador e a foto são componentes distintos e nenhum cabe junto com outro dentro do limite de task.
- A execução da OS (evento, avistamento, adequação, assinatura) **não** está aqui: é o Plano 13. Esta entrega termina com o técnico vendo o dia offline e a fila funcionando de ponta a ponta com operações genéricas.
- HTTPS é obrigatório: sem ele não há service worker nem câmera. Conferir o certificado do ambiente antes do deploy do aplicativo.
