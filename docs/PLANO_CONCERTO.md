# Plano de concerto — rastreio

Branch de implementação: `fix/concerto-plano`  
Auditoria base: [`relatorios/AUDITORIA_2026-11-11.md`](relatorios/AUDITORIA_2026-11-11.md)

## Fase 0 — Linha de base

| ID | Item | Estado | Evidência |
|----|------|--------|-----------|
| 0.1 | Este documento | [x] | 2026-11-12 |
| 0.2 | Smoke prod (pré-deploy) | [x] | `.git/HEAD` 200, `/docs/` 200, listagem `admin/api/` |
| 0.3 | Smoke local Docker | [ ] | Correr após `docker compose up` |
| 0.4 | README VPS | [x] | `README.md` atualizado |

## Fase 1 — Infra (S1, S2, S3, S10)

| ID | Item | Estado | Notas |
|----|------|--------|-------|
| 1.1 | Rotação SSH | [ ] | Runbook: [`runbooks/SSH-ROTACAO-E-BACKUP.md`](runbooks/SSH-ROTACAO-E-BACKUP.md) |
| 1.2 | `.htaccess` raiz | [x] | Código commitado; smoke pós-deploy |
| 1.3 | vhost / deny `.git` | [ ] | Validar no cPanel após deploy |
| 1.4 | Docs sem segredos | [x] | `docs/INFRA_VPS.md` redigido |
| 1.5 | Backup VPS | [ ] | Marcos / runbook |
| 1.6 | Cloudflare Always HTTPS | [ ] | Painel CF |

## Fase 2 — Admin (S4)

| ID | Item | Estado |
|----|------|--------|
| 2.1 | `session.php` mínimo anónimo | [x] |
| 2.2 | Cookies sessão seguros | [x] |
| 2.3 | Rate-limit login | [x] | SQL: `scripts/vps/05-login-tentativas.sql` + fallback ficheiro |
| 2.4 | MIME uploads | [x] |

## Fase 3 — Conversão (S5, S7, S8)

| ID | Item | Estado |
|----|------|--------|
| 3.1 | Carousel vazio | [x] | `carousel.js` |
| 3.2 | Remover Apple Pay (HTML) | [x] |
| 3.3 | Galeria comentários oculta; CSS antes/depois | [x] |
| 3.4–3.7 | Kiwify | [ ] | [`runbooks/KIWIFY-CHECKOUT.md`](runbooks/KIWIFY-CHECKOUT.md) |

## Fase 4 — SEO híbrido (S6)

| ID | Item | Estado |
|----|------|--------|
| 4.1 | `noindex` só admin/user | [x] |
| 4.2 | `robots.txt` | [x] |
| 4.3 | `sitemap.xml` | [x] |
| 4.4 | OG, canonical, favicon | [x] |
| 4.5 | JSON-LD Product | [x] |
| 4.6 | Preview social manual | [ ] |

## Fase 5 — Performance (S11, S10)

| ID | Item | Estado |
|----|------|--------|
| 5.1 | lazy/decoding imagens abaixo da dobra | [x] |
| 5.2 | srcset hero variantes | [ ] | Adiar até gerar WebP 480/800 |
| 5.3 | `404.html` | [x] |
| 5.4 | Headers CF / CSP Report-Only | [ ] | Parcial via `.htaccess`; CF manual |

## Fase 6 — Conteúdo (S9)

| ID | Item | Estado |
|----|------|--------|
| 6.1 | Bloco autor | [x] |
| 6.2 | +2 páginas PDF amostra | [ ] | Conteúdo Marcos |
| 6.3 | Vídeo hero/como | [ ] | Conteúdo Marcos |
| 6.4 | 6 FAQ novas | [x] |

## Fase 7 — Revisão final

| ID | Item | Estado |
|----|------|--------|
| 7.1 | `smoke-prod.ps1` pós-deploy | [ ] |
| 7.2 | Security review diff | [x] | Ajustes: `admin/var/` + CF-Connecting-IP |
| 7.3 | Veredito | [ ] | APROVADO após deploy + smoke verde |

## Rollback

- Código: `git reset --hard` no commit anterior (servidor)
- Conteúdo: `index.html.bak.*` do CMS
