# Infraestrutura (resumo público)

Este ficheiro **não** contém IPs, portas SSH, utilizadores nem nomes de secrets.

## Produção

- Site: **https://fazendoemcasa.com/**
- Stack: PHP 8.2 + MySQL (CMS em `/admin/`)
- Deploy: push na `main` → GitHub Actions → `git reset --hard` no servidor

## Local

- Pasta: `C:\laragon\www\fazendoemcasa`
- Docker: ver [`docker/README.md`](../docker/README.md) (porta **8080**)

## Detalhe operacional

Mapas com credenciais, caminhos no servidor e rotação de chaves ficam **fora do repositório** (gestor de senhas ou cópia local gitignored). Usar também [`docs/CURSOR_CONTEXTO.md`](CURSOR_CONTEXTO.md) e a regra `fazendoemcasa-core.mdc`.
