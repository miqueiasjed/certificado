---
name: commit-push
description: Valida, commita e faz push apenas das alterações da tarefa atual, com mensagem semântica gerada a partir do diff. Use quando o usuário pedir para commitar/enviar o trabalho ou ao final de um plano.
---
# Skill: Commit e Push

Gera uma mensagem de commit adequada com base no diff e faz o push para o repositório remoto.

## Contexto do projeto

<!-- PROJETO-ESPECIFICO: esta é a única seção que varia entre projetos. Ao sincronizar esta skill, preserve este bloco. -->
- **Type-check:** não se aplica (frontend Vue 3 `<script setup>` em JavaScript puro, sem TypeScript)
- **Build:** `npm run build` (se mexeu em arquivos Vue/JS/CSS)
- **Testes backend:** `php artisan test --filter=NomeDoModulo`
- **Particularidades:** além dos resíduos de debug padrão, verificar também `var_dump()` (PHP) e `console.error()` esquecido (JS/Vue)

## Princípio de escopo (OBRIGATÓRIO)

Tudo neste fluxo se restringe **apenas ao que VOCÊ alterou nesta tarefa**:

- Rode somente os testes relacionados ao que você mexeu; **nunca** a suíte inteira nem testes de módulos sem relação.
- Erros/avisos **preexistentes** em arquivos que você não tocou não são sua responsabilidade e **não bloqueiam** o commit. Não tente corrigi-los; confirme que são preexistentes e siga.
- No commit, adicione **apenas os arquivos da sua alteração**. Nada de `git add .` cego que arraste mudanças alheias.

## Validações pré-commit (somente sobre o que você alterou)

1. **Código de debug:** confira nos arquivos alterados se sobrou `dd()`, `dump()`, `print_r()`, `console.log()` ou `debugger`. Remova.
2. **Type-check (se mexeu em frontend):** rode o comando do projeto e corrija apenas os erros nos arquivos que você alterou.
3. **Build (se mexeu em frontend):** rode o build do projeto e confirme que termina sem erros. O build é o gate de deploy: erro de build bloqueia o commit.
4. **Testes (se mexeu em backend):** rode somente os testes estritamente relacionados à sua alteração e garanta que passem.

## Commit e push

5. Veja o estado atual: `git status` e `git diff`.
6. Adicione **apenas** os arquivos da sua tarefa, listando-os explicitamente: `git add <arquivo1> <arquivo2> ...`. Só use `git add -A` se todo o working tree for fruto da sua tarefa.
7. Mensagem semântica baseada apenas nas suas alterações (ex.: `feat: adiciona módulo X`, `fix: corrige erro Y`).
8. `git commit -m "[mensagem]"` e `git push`.
9. Informe ao usuário o resultado de cada etapa (testes, type-check, build, git), inclusive o que falhou.
