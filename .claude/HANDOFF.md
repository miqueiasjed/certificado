# Handoff

Plano: 22
Task: -
Estado: Concluído e commitado (f8c988b), não publicado
Tentativas: -
Base Git: f8c988b (local, à frente de origin/main em 2 commits - Planos 21 e 22)
Feito: Plano 22 (Roteirização e rastreamento em campo) completo, 8 tasks. Coordenada de endereço (geocodificação via Nominatim, gratuito), roteiro do dia por técnico ordenado por proximidade com âncora de horário, mapa das visitas (Leaflet), registro de local de início/fim de execução com consentimento, divergência de local e rastreamento contínuo opcional desligado por padrão. Suíte completa do projeto: 1.285 testes, 10.745 asserções, tudo verde. `npm run build` limpo. Quatro achados corrigidos durante a execução (registrados em `.claude/progress.txt`): coordenada de sede fictícia `(0,0)` inflando a distância total do roteiro; divergência de local zerada abaixo do limiar (apagava o dado que a task existe para preservar); reordenação manual não recalculava totais; rastreamento contínuo não exposto ao aplicativo.
Falta: nenhuma ação pendente no Plano 22, exceto commit (e push, se autorizado).
Erro reproduzível: nenhum erro ativo
Arquivos alterados: ver `git status` — abrange schema, models, services (Geo, Routing), controllers, rotas, permissões, seeders, telas web (painel de roteiro, mapa, pendências geo) e do app do técnico (roteiro, captura de local), e testes do Plano 22 inteiro. `.claude/plans/INDEX.md` e `.claude/tasks/22/INDEX.md` marcados como concluídos.
Risco residual: geocodificação real em produção usa Nominatim (gratuito, sem chave) — respeitar a política de uso deles (User-Agent, ~1 req/s) ao rodar o backfill em lote real. Rastreamento contínuo de técnico é dado pessoal sensível: nasce desligado, só liga com consentimento registrado, precisa de decisão do responsável do tenant antes de ativar para qualquer técnico. Coordenada de sede ainda não existe (`companies` sem lat/lng) — o roteiro funciona sem ela (primeiro trecho não entra no total), mas fica menos preciso; task futura pode resolver. Deploy em quatro etapas conforme `.claude/tasks/22/INDEX.md` ("Ordem de aplicação em produção") — módulo `roteirizacao` desligado até o Deploy 3/4, geocodificação em lote rodada com `--dry-run` primeiro e pendências de precisão "cidade" corrigidas manualmente antes de seguir.
Próxima ação: commitar localmente (sem push, sem autorização explícita do usuário para isso). Depois, cinco planos ficam com dependências satisfeitas: 23 (Comissões, metas e renovação de contratos), 24 (Conformidade RDC 622/2022), 25 (Laudo assistido por IA), 26 (Assinatura eletrônica de contratos), 27 (Frota e veículos, liberado agora que o 22 terminou). Escolher pela ordem de execução recomendada em `.claude/plans/INDEX.md` ao retomar.

Fora do escopo deste plano, deixados como estavam (não tocar sem pedido explícito):
- `.claude/skills/run-plan/SKILL.md`: modificado, não commitado (sync de skill, sessão anterior).
- `public/vendas.html`: arquivo novo não rastreado, sem relação com nenhum plano ativo.
- `.DS_Store` / `public/.DS_Store`: lixo do macOS, nunca deve ir para commit.

## Histórico

### Plano 22 (concluído)
Ver `.claude/progress.txt` para os quatro aprendizados duráveis registrados.

### Plano 21 (concluído)
Ver `.claude/progress.txt` para os quatro aprendizados duráveis registrados. Commitado localmente em 9ebd7db, não publicado (push pendente de autorização).

### Plano 20 (concluído)
Nota fiscal de serviço commitada (81 arquivos, +10339/-76) e enviada ao remoto (ddc674a..cac9ca4). Suíte completa (1.192 testes, 10.040 asserções) e build Vite confirmados antes do push.
