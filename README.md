# Fazendo em Casa

Site estático em [fazendoemcasa.com](https://fazendoemcasa.com/), hospedado no **GitHub Pages** (branch `main`, raiz do repositório).

## Fluxo (igual ao Elba Food, sem VPS)

1. Crie uma branch a partir da `main` atualizada: `git fetch origin` e `git switch -c fix/alguma-coisa origin/main`
2. Commite as alterações na branch.
3. Publique: `.\scripts\deploy.ps1 -Branch fix/alguma-coisa` (confirme com **SIM**) ou `-Confirmar` quando pedir commit e push explícito.

O script faz: `push` da branch → merge na `main` → `push` da `main`. O GitHub Pages publica em 1–3 minutos.

## Desenvolvimento local

Abra a pasta no Laragon ou sirva os arquivos com qualquer servidor estático.

### Painel administrativo (página inicial)

No Laragon (PHP ativo): `http://fazendoemcasa.test/admin/` (ou o virtual host que usares).

1. **Editar** — menu lateral com cada texto/imagem: mostra *o quê* muda e *onde* no site.
2. **Guia de configuração** — tabela com mapeamento completo (secção, seletor CSS, ID).
3. Imagens: upload com compressão WebP (largura máx. e qualidade ajustáveis).
4. **Guardar JSON** → grava `admin/content.json`.
5. **Aplicar ao index.html** → atualiza a landing (cria backup `index.html.bak.*`).

PIN inicial: `2828`. Copia `admin/config.example.php` para `admin/config.local.php` e altera o PIN (`config.local.php` não vai para o Git).

Em GitHub Pages o painel é só leitura/exportação (sem PHP): descarrega JSON e `index.html` e faz commit manual.

O site público em produção continua estático; não expõe o painel na mesma URL do Pages unless commits incluem `/admin`.

## Cursor

Ao abrir a pasta, a tarefa **git: puxar ao abrir o Cursor** roda `git fetch` / `git pull --ff-only` (como no Elba Food).

## Produção

- **Não** usa SSH na VPS do Elba Food: o domínio aponta para GitHub Pages (`CNAME`).
- Push na `main` dispara o build do Pages automaticamente.
- O workflow `Deploy GitHub Pages` só verifica se o site responde após o deploy.
