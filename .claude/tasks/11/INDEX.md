# Tasks do Plano 11 - QR code e identificação de dispositivos

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 11.1 | Migration e model: código público e substituição | backend-estrutura | ⏳ | baixa |
| 11.2 | Geração do código público, backfill e unique composto | backend-logica | ⏳ | média |
| 11.3 | Service de QR code e folha de etiquetas em PDF | backend-logica | ⏳ | média |
| 11.4 | Endpoint de leitura com validação contra a OS | backend-endpoint | ⏳ | média |
| 11.5 | Substituição preservando o histórico do ponto | backend-endpoint | ⏳ | média |
| 11.6 | Cadastro em lote de dispositivos do endereço | backend-endpoint | ⏳ | média |
| 11.7 | Componente leitor de QR code pela câmera | frontend-componente | ⏳ | alta |
| 11.8 | Telas de etiqueta, lote e substituição | frontend-pagina | ⏳ | alta |
| 11.9 | Testes de leitura, substituição e lote | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             11.1                 (deploy 1: coluna nullable)
Lote 2:             11.2                 (deploy 2: backfill; deploy 3: NOT NULL)
Lote 3 (paralelo):  11.3  |  11.5  |  11.6
Lote 4:             11.4                 (toca routes/web.php depois de 11.5 e 11.6)
Lote 5:             11.7
Lote 6:             11.8
Lote 7:             11.9
```

## Dependências internas

- 11.2 depende de 11.1
- 11.3, 11.4, 11.5 e 11.6 dependem de 11.2 (o código precisa existir e ser único)
- 11.7 depende de 11.4 (consome as quatro situações da leitura)
- 11.8 depende de 11.3, 11.5, 11.6 e 11.7
- 11.9 depende de 11.4, 11.5 e 11.6

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 11.4, 11.5, 11.6 | Uma por vez, nesta ordem: 11.5, 11.6, 11.4 |
| `app/Models/Device.php` | 11.1, 11.2 | 11.1 antes de 11.2 |
| `resources/js/Pages/Devices/Index.vue` | 11.8 | Task única |
| `composer.json` / `package.json` | 11.3, 11.7 | Podem rodar em paralelo: arquivos diferentes |

## Decisões registradas

- **O QR codifica a URL de leitura, não o código puro.** A câmera nativa do celular abre URL sem precisar do aplicativo, e o técnico chega à tela em um passo.
- **Alfabeto sem caractere ambíguo** (`I`, `O`, `0`, `1` fora). Etiqueta suja não lê, e a digitação manual é o plano B real.
- **Dispositivo fora do endereço da OS gera aviso, não bloqueio.** O técnico às vezes confere um ponto de propósito. O que não se admite é registrar errado em silêncio.
- **Código público é imutável.** Etiqueta impressa e colada continua no local, então reetiquetar é substituição, não edição.
- **Nenhum evento migra na substituição.** O histórico do ponto vem da cadeia em `device_replacements`, porque mover evento falsificaria o registro.
- **Teto de 200 por lote.** Acima disso a requisição estoura o tempo limite e o usuário fica sem saber o que foi criado.

## Ordem de aplicação em produção

1. **Deploy 1** (Task 11.1): coluna `codigo_publico` nullable e tabela de substituição. Sem efeito observável.
2. **Deploy 2** (Task 11.2, parte do comando): rodar `dispositivos:backfill-codigo --dry-run`, conferir a contagem contra `select count(*) from devices`, rodar sem a flag.
3. **Deploy 3** (Task 11.2, migration final): NOT NULL e unique composto. Só depois do backfill conferido.
4. **Deploy 4** (11.3 a 11.9): funcionalidade visível. As etiquetas antigas do cliente atual, se existirem em papel, continuam válidas porque `number` não foi alterado.

## Observações

- O plano estimava ~6 tasks. A decomposição chegou a 9 porque a coluna nova exige a mesma disciplina de três etapas do Plano 4, e porque o leitor de câmera não cabe junto com as telas.
- `active` continua existindo e não é substituído por `situacao` nesta entrega. Unificar os dois é dívida técnica anotada, e mexer nisso agora mudaria o comportamento das telas atuais do cliente em produção.
- A leitura registra evento apenas no Plano 13. Aqui ela só resolve e navega.
