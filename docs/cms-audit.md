# FASE 0 — Auditoria do CMS atual

Data: 2026-11-16
Branch: a criar a partir de `origin/main`
Escopo: leitura apenas (nenhuma alteração estrutural).

> Incidente durante a auditoria: `admin/tools/extract-content.php` foi
> acessível por HTTP e regravou `admin/content.json` em produção.
> Restaurado com `git checkout --`; MD5 confirmado igual ao HEAD
> (`6ce9bd5ae93449e0c9d4d7d342d630c2`).

---

## 1. Inventário real do repositório

### 1.1 Raiz (`/`)

| Item | Tipo | Observação |
|---|---|---|
| `index.html` (57 KB) | LP principal PT | 60 `<img>`, 6 `data-cms-src`, 6 `data-photo` |
| `style.css` (59 KB) | CSS global | sem `url()` de imagem |
| `carousel.js`, `cookies.js`, `lightbox.js`, `sticky-cta.js`, `utm.js`, `video-embed.js` | JS | compartilhados |
| `privacidade.html`, `termos.html`, `404.html` | páginas legais | estáticas |
| `img/cms/` | 12 WebP + `.htaccess` | uploads do CMS |
| `img/gallery-demo/` | demo | não usado em produção |
| `video/cms/` | vazio (`.gitkeep`) | uploads de vídeo |
| `kit-activites-tea/` | **projeto** | LP FR, `index.html` 31 KB + `assets/` 21 WebP |
| `reliure-artisanale/` | **projeto** | LP FR, `index.html` 34 KB + `assets/` 10 WebP |
| `admin/` | painel | ver 1.2 |
| `user/` | área de membros | login + `member_users` |
| `docs/`, `scripts/`, `docker/`, `.github/` | infra | bloqueados por `.htaccess` |

### 1.2 `admin/`

| Arquivo | Linhas / KB | Função |
|---|---|---|
| `index.html` | 140 linhas | shell do painel (login + app) |
| `admin.js` | **2.466 linhas / 88 KB** | todo o editor, preview, upload, publish |
| `admin.css` | 656 linhas | tema verde `--adm-accent: #1f5c45` |
| `site-map.json` | 93 KB | **16 grupos, 282 campos** |
| `content.json` | 24 KB | documento de conteúdo (espelho do banco) |
| `api/*.php` | 14 arquivos | endpoints JSON |
| `tools/*.php` | 11 arquivos | scripts CLI **sem proteção web** |
| `var/` | `.htaccess` | rate-limit em arquivo |

### 1.3 Tipos de campo no `site-map.json`

| Tipo | Qtd |
|---|---|
| `text` | 156 |
| `html` | 52 |
| `image` | 51 |
| `attr` | 12 |
| `image-slot` | 6 |
| `video-embed` | 2 |
| `video-file` | 2 |
| `choice` | 1 |
| **Total** | **282** |

Os 16 grupos: `seo`, `cabecalho`, `hero`, `programa`, `para-quem`,
`como-funciona`, `incluido`, `receitas`, `depoimentos`, `videos`,
`galeria-fotos`, `galeria-comentarios`, `garantia`, `faq`, `oferta`,
`cta-final`.

### 1.4 Banco de dados (produção, MySQL 8.0.46)

| Tabela | Linhas | Observação |
|---|---|---|
| `cms_content` | 1 | `id=1`, documento JSON 22.931 bytes |
| `cms_revisions` | 37 | histórico completo, último `2026-11-14 11:10` |
| `cms_media` | 21 | log de uploads |
| `admin_users` | 1 | conta única |
| `member_users` | 0 | área de membros sem uso |
| `login_tentativas` | 0 | rate-limit |

Todas `utf8mb4_unicode_ci`. **Não existe nenhuma coluna de projeto** —
o schema é single-tenant por desenho (`cms_content.id = 1`).

### 1.5 Produção (VPS)

- Document root: `/home/elbasoftcom/fazendoemcasa_www`
- PHP 8.2.35, `memory_limit` 512M, upload 64M
- Extensões: `dom`, `gd`, `imagick`, `pdo_mysql`, `mbstring`, `fileinfo`,
  `zip`, `tokenizer`, `tidy`, `xsl` — **`composer` disponível**
- Disco: 47 GB livres (88% usado)
- `admin/`, `img/cms/`, `index.html` graváveis pelo PHP
- `painel` é **symlink** para `admin` (não versionado)
- Arquivos não versionados em produção: `_probe.txt`,
  `admin/tools/error_log`, 3 WebP em `img/cms/`

---

## 2. Como o CMS funciona hoje (fluxo real, lido no código)

```
Browser (/admin/)
   │  login.php → sessão PHP (cookie HttpOnly, SameSite=Strict)
   ▼
admin.js  ── GET  content.php ──────────► cms_content.document (MySQL)
   │                                     └ fallback admin/content.json
   │  edita em memória (state.content)
   │
   ├── POST upload-image.php ──────────► img/cms/<fieldId>.webp + cms_media
   ├── POST save-content.php ──────────► UPDATE cms_content + INSERT cms_revisions
   └── POST apply-index.php ───────────► REESCREVE index.html inteiro (+ .bak)
```

### 2.1 O mecanismo de edição é duplo e frágil

Existem **dois motores de aplicação** rodando em paralelo:

1. **Genérico** (`applyField` / `readField`, `admin.js:680-789`):
   percorre os 282 campos do `site-map.json` e faz
   `doc.querySelector(selector)` → `textContent` / `innerHTML` /
   `setAttribute`.
2. **Específico** (`applyVideoBlocks`, `applyImageSlot`,
   `applyCommentSlides`, `applyProgramaMedia`, `applyAutoCarousel`,
   `applyFeaturePhotos`, `updateGaleriasVisibility`, `applyAllToDoc`):
   ~10 funções hardcoded que conhecem IDs e classes do `index.html`.

O segundo conjunto é necessário porque seletores CSS simples não expressam
"modo do slot" (`image` / `text` / `both`), carrosséis, ou visibilidade
condicional de seções inteiras.

**Consequência:** a "edição genérica" prometida é, na prática, específica
deste único HTML. Não há como reaproveitar o motor para um segundo projeto.

### 2.2 Não há AST — há reescrita de HTML inteiro

`apply-index.php` recebe o HTML completo serializado pelo browser
(`serializeDoc`, `admin.js:2083`) e **sobrescreve `index.html`**.
Não há parsing, não há diff, não há patch cirúrgico.

Riscos concretos:

- Qualquer bug no DOM do navegador vira corrupção do arquivo publicado.
- Não há validação do HTML recebido além de `strpos($html, '<html')`.
- Perde formatação, comentários e indentação originais.
- Não serve para arquivos PHP nem para projetos com includes.

### 2.3 O que já funciona bem e deve ser preservado

| Ativo | Valor |
|---|---|
| `cms_revisions` + `content.json` como espelho | histórico confiável, 37 revisões |
| `admin_write_bytes()` (`bootstrap.php:85`) | resolve o problema real de arquivo `root:644` no cPanel via `rename()` |
| `admin_detect_mime()` com fallback manual | funciona sem `fileinfo` |
| Rate-limit de login (tabela + fallback arquivo) | robusto, com `CF-Connecting-IP` |
| Sessão com `SameSite=Strict`, `HttpOnly`, `Secure` condicional | correto |
| `sanitizeVideoEmbed` + allowlist de hosts | bom padrão a generalizar |
| Backup `.bak.<timestamp>` antes de publicar | embrião do versionamento |
| Tema do `admin.css` (verde, sóbrio) | base visual aproveitável |

---

## 3. Diagnóstico: os 12 bloqueios para o multi-projeto

| # | Bloqueio | Evidência | Impacto |
|---|---|---|---|
| **B1** | `admin/tools/*.php` executável pela web | `HTTP 200` + escrita real em `content.json` | 🔴 **RCE / corrupção** |
| **B2** | Schema sem `project_id` | `cms_content.id = 1` fixo | 🔴 multi-tenant impossível sem migração |
| **B3** | `site-map.json` único e específico | 282 campos de 1 página | 🔴 cada projeto exigiria um mapa manual |
| **B4** | Publicação = reescrever HTML inteiro | `apply-index.php` | 🔴 não escala, corrompe, não serve PHP |
| **B5** | Sem CSRF em nenhum POST | `grep csrf` = 0 resultados | 🟠 escrita cross-site |
| **B6** | Sem isolamento de caminho | `admin_paths()` fixo em `dirname(__DIR__)` | 🔴 base para path traversal |
| **B7** | Sem upload de arquivo real (só base64 data-URL) | `upload-image.php` | 🟠 8 MB limit, sem multi-upload |
| **B8** | Sem biblioteca de mídia | `img/cms/` plano, sem metadados | 🟠 sem substituição rastreável |
| **B9** | Sem rascunho/publicação separados | salvar = publicar | 🟠 sem preview real |
| **B10** | Sem permissões por usuário | `admin_users` sem `role` | 🟠 conta única |
| **B11** | Scanner inexistente | `extract-content.php` só lê `site-map` | 🔴 nada de discovery |
| **B12** | `admin.js` monolítico 2.466 linhas | sem módulos | 🟠 manutenção inviável |

### 3.1 B1 em detalhe — o achado mais grave

```
GET https://fazendoemcasa.com/admin/tools/extract-content.php
→ 200  "OK: /home/elbasoftcom/fazendoemcasa_www/admin/content.json"
```

O `.htaccess` da raiz bloqueia `docs/`, `scripts/`, `docker/`, `.git`,
`admin/var/` e `config.local.php` — **mas não `admin/tools/`**.

Três dos scripts são inofensivos sem argumentos CLI
(`extract-content.php` só regrava o JSON a partir do HTML,
`patch-gallery-content.php` imprime `OK`), dois retornam 500
(`migrate-json-to-db.php`, `create-admin-user.php` — dependem de
`getopt`/`$config` que só existem no CLI).

Mas `error_log` também estava público (403 agora) e o padrão é claro:
**qualquer `tools/*.php` novo vira endpoint público**. Se um desses
scripts aceitar parâmetro via `$_GET` no futuro, é execução remota.

---

## 4. Conclusão da FASE 0

O CMS atual é um **editor de uma única landing page**, bem feito para o
que se propôs, mas estruturalmente single-tenant em **todas** as camadas:

- banco (`id=1`),
- mapa de campos (um JSON),
- motor de aplicação (funções hardcoded),
- publicação (reescreve um `index.html`),
- mídia (uma pasta plana),
- navegação (uma tela).

Transformá-lo em central multi-projeto **não é refatoração — é
construção paralela**. O correto é:

1. **Não tocar** no editor atual enquanto a central nova não estiver
   publicando com segurança.
2. Extrair do atual os 3 ativos reais reaproveitáveis:
   `admin_write_bytes`, `admin_detect_mime`, rate-limit.
3. Construir o multi-tenant em namespaces novos (`admin/cms/`),
   com schema próprio (`cms_projects` + `project_id` em tudo).
4. Manter `index.html` da raiz sob o editor legado até a FASE 6 provar
   paridade funcional.

A arquitetura proposta está em [`cms-architecture.md`](cms-architecture.md).

