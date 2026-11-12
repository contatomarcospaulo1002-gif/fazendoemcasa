# Contexto para o agente (Fazendo em Casa)

Use este arquivo + `.cursor/rules/fazendoemcasa-core.mdc`. Atualizado após deploy na VPS.

## Produção

- **URL:** https://fazendoemcasa.com/
- **Admin:** https://fazendoemcasa.com/admin/ (PHP + MySQL)
- **VPS:** mesmo servidor Elba — `ssh elbafood-prod`
- **Código no servidor:** `/home/elbasoftcom/fazendoemcasa_www`
- **MySQL:** `elbasoftcom_fazendoemcasa` / user `elbasoftcom_fecms`
- **Deploy:** push `main` dispara Actions; ou `scripts/deploy.ps1`

## DNS

Domínio apontado para `129.121.35.178`. Desligar GitHub Pages no repo se ainda estiver ativo.

## Desenvolvimento local

`C:\laragon\www\fazendoemcasa` — Docker ou Laragon; ver README.md e `docker/README.md`.

Documentação VPS: `docs/INFRA_VPS.md`, `scripts/vps/README-VPS.md`.
