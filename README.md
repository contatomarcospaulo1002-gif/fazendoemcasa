# Fazendo em Casa

Site estático em [fazendoemcasa.com](https://fazendoemcasa.com/), hospedado no **GitHub Pages** (branch `main`, raiz do repositório).

## Fluxo (igual ao Elba Food, sem VPS)

1. Crie uma branch a partir da `main` atualizada: `git fetch origin` e `git switch -c fix/alguma-coisa origin/main`
2. Commite as alterações na branch.
3. Publique: `.\scripts\deploy.ps1 -Branch fix/alguma-coisa` (confirme com **SIM**) ou `-Confirmar` quando pedir commit e push explícito.

O script faz: `push` da branch → merge na `main` → `push` da `main`. O GitHub Pages publica em 1–3 minutos.

## Desenvolvimento local

Abra a pasta no Laragon ou sirva os arquivos com qualquer servidor estático. Não há PHP nem banco.

## Cursor

Ao abrir a pasta, a tarefa **git: puxar ao abrir o Cursor** roda `git fetch` / `git pull --ff-only` (como no Elba Food).

## Produção

- **Não** usa SSH na VPS do Elba Food: o domínio aponta para GitHub Pages (`CNAME`).
- Push na `main` dispara o build do Pages automaticamente.
- O workflow `Deploy GitHub Pages` só verifica se o site responde após o deploy.
