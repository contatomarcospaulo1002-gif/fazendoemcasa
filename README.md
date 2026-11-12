# Fazendo em Casa

Landing e CMS em [fazendoemcasa.com](https://fazendoemcasa.com/) — **VPS** (PHP 8.2 + MySQL), não GitHub Pages.

## Fluxo de trabalho

1. `git fetch origin` e `git switch -c fix/nome origin/main`
2. Implementar e testar (Docker ou Laragon)
3. Commit na branch; merge na `main` quando apropriado
4. Push na `main` → GitHub Actions **Deploy produção** (SSH `git pull` no servidor)
5. Deploy manual alternativo: `.\scripts\deploy.ps1` (confirmação explícita)

## Desenvolvimento local

- **Laragon:** virtual host ou pasta `C:\laragon\www\fazendoemcasa`
- **Docker:** ver [`docker/README.md`](docker/README.md) — `http://localhost:8080/`
- Admin: `/admin/` — PIN em `admin/config.local.php` (gitignored; exemplo em `admin/config.example.php`)

## Plano de qualidade

- Checklist vivo: [`docs/PLANO_CONCERTO.md`](docs/PLANO_CONCERTO.md)
- Fluxo agentes: [`docs/FLUXO_AGENTES.md`](docs/FLUXO_AGENTES.md)
- Smoke produção: `.\scripts\smoke-prod.ps1`

## Cursor

Contexto: [`docs/CURSOR_CONTEXTO.md`](docs/CURSOR_CONTEXTO.md) + regra `fazendoemcasa-core.mdc`.
