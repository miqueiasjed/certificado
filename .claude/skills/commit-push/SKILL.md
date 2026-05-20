---
name: commit-push
description: Realizar commit e push de todas as alterações para o repositório
---

# Skill: Commit e Push

Este workflow valida, realiza o commit das alterações com mensagem semântica e faz push para o repositório remoto.

## Fluxo de Execução

### Validações Pré-Commit

1. **Código de debug:** verifique se há resíduos de debug nos arquivos alterados. Remova qualquer ocorrência acidental de:
   - PHP: `dd()`, `dump()`, `var_dump()`, `print_r()`
   - JS/Vue: `console.log()`, `debugger`, `console.error()` deixados acidentalmente

2. **Frontend (se houver alterações em arquivos Vue/JS/CSS):**
   - Execute `npm run build` e confirme que termina **sem erros**.
   - Erros de build bloqueiam o deploy — não comitar sem build verde.

3. **Backend (se houver alterações em PHP):**
   - Identifique os módulos tocados e rode os testes relacionados: `php artisan test --filter=NomeDoModulo`
   - Garanta que passem todos no verde.

### Commit e Push

4. Verifique o status: `git status`
5. Revise as alterações: `git diff` e `git diff --cached`
6. Adicione os arquivos: `git add .`
7. Gere uma mensagem de commit semântica baseada no diff:
   - `feat: descrição` — nova funcionalidade
   - `fix: descrição` — correção de bug
   - `refactor: descrição` — refatoração sem nova feature
   - `style: descrição` — ajustes visuais/CSS
   - `chore: descrição` — tarefas de manutenção
8. Execute o commit: `git commit -m "[mensagem gerada]"`
9. Faça o push: `git push`
10. Informe o resultado detalhado — o que passou, o que falhou.
