# Roadmap — Central CMS multi-projeto

Base: [`cms-audit.md`](cms-audit.md) · [`cms-architecture.md`](cms-architecture.md)

Legenda: `[ ]` pendente · `[~]` em curso · `[x]` concluído com teste

---

## FASE 0 — Auditoria ✅

| ID | Item | Estado |
|---|---|---|
| 0.1 | Inventário de arquivos, pastas, banco e produção | [x] |
| 0.2 | Leitura do fluxo real do CMS atual | [x] |
| 0.3 | Diagnóstico dos 12 bloqueios | [x] |
| 0.4 | Arquitetura proposta | [x] |
| 0.5 | Documentos `cms-*.md` | [x] |

**Achado crítico:** `admin/tools/*.php` executável pela web (B1).
Correção entra na FASE 1 como item 1.0, antes de qualquer feature.

---

## FASE 1 — Central de Projetos

| ID | Item | Estado |
|---|---|---|
| 1.0 | Bloquear `admin/tools/` (`.htaccess` + guard CLI) | [x] |
| 1.1 | Bootstrap do namespace `FazendoEmCasa\Cms` + autoload | [x] |
| 1.2 | Migrations: `cms_projects`, `cms_migrations` | [x] |
| 1.3 | `ProjectRepository` + `ProjectGuard` (isolamento) | [x] |
| 1.4 | `DiscoveryEngine` (varredura da raiz autorizada) | [x] |
| 1.5 | Lista de exclusão configurável | [x] |
| 1.6 | API: listar / criar / editar / desativar projeto | [x] |
| 1.7 | UI: Central de Projetos (cards) | [x] |
| 1.8 | UI: Adicionar projeto | [x] |
| 1.9 | Testes: descoberta, cadastro, isolamento, path traversal | [x] |

**Critério de saída:** cadastrar `kit-activites-tea` e
`reliure-artisanale`, ver os dois cards, e um teste automatizado provar
que `project_id` forjado não acessa pasta alheia.

---

## FASE 2 — Scanner

| ID | Item | Estado |
|---|---|---|
| 2.1 | Migrations: `cms_pages`, `cms_scans` | [x] |
| 2.2 | `HtmlAnalyzer` (DOMDocument) | [x] |
| 2.3 | `PhpAnalyzer` (`token_get_all`) | [x] |
| 2.4 | `CssAnalyzer` | [x] |
| 2.5 | `JsAnalyzer` + `JsonAnalyzer` | [x] |
| 2.6 | `PageDiscovery` (entrypoints, includes, templates) | [x] |
| 2.7 | Scanner incremental por `content_hash` | [x] |
| 2.8 | UI: Páginas do projeto | [x] |
| 2.9 | Testes: descoberta de páginas, imagens, textos | [x] |

**Critério de saída:** mapear as 3 páginas reais (raiz, kit, reliure) e
listar imagens/textos de cada uma sem executar PHP.

---

## FASE 3 — Content Registry

| ID | Item | Estado |
|---|---|---|
| 3.1 | Migration: `cms_elements` | [x] |
| 3.2 | `ElementRegistry` (upsert por `page_id + selector + type`) | [x] |
| 3.3 | `Classifier` (4 níveis de editabilidade) | [x] |
| 3.4 | Adaptadores: `HtmlAdapter`, `ComponentAdapter` | [x] |
| 3.5 | Adaptadores: `PhpAdapter`, `CssAdapter`, `JsonAdapter` | [x] |
| 3.6 | `LegacyAdapter` (ponte para `site-map.json`) | [x] |
| 3.7 | UI: estrutura da página (árvore) | [x] |
| 3.8 | Testes: registro, classificação, leitura por adaptador | [x] |

**Critério de saída:** cada elemento tem nível de editabilidade e motivo;
nenhum elemento é marcado `editable` sem adaptador que prove escrita.
✅ **Cumprido** — 363/363, ver [cms-phase3.md](cms-phase3.md).

---

## FASE 4 — Editor Visual

| ID | Item | Estado |
|---|---|---|
| 4.1 | Migration: `cms_drafts` | [ ] |
| 4.2 | `DraftService` (patch por elemento) | [ ] |
| 4.3 | `PreviewRenderer` (memória, sem disco) | [ ] |
| 4.4 | UI: 3 colunas (árvore / preview / propriedades) | [ ] |
| 4.5 | Editores por tipo: texto, imagem, botão, fundo | [ ] |
| 4.6 | Preview desktop / tablet / mobile | [ ] |
| 4.7 | Testes: edição, rascunho, preview | [ ] |

---

## FASE 5 — Biblioteca de Mídia

| ID | Item | Estado |
|---|---|---|
| 5.1 | Migrations: `cms_media`, `cms_media_references` | [ ] |
| 5.2 | Upload real (multipart) + validação MIME | [ ] |
| 5.3 | `ReferenceIndex` (onde cada imagem é usada) | [ ] |
| 5.4 | Substituição com escolha de escopo | [ ] |
| 5.5 | UI: grade, filtros, categorias, não utilizadas | [ ] |
| 5.6 | Testes: upload, referências, substituição | [ ] |

---

## FASE 6 — Publicação

| ID | Item | Estado |
|---|---|---|
| 6.1 | Migrations: `cms_publications`, `cms_backups` | [ ] |
| 6.2 | `BackupService` (snapshot antes de escrever) | [ ] |
| 6.3 | `Publisher` + `AtomicWriter` | [ ] |
| 6.4 | Verificação pós-escrita (hash + re-parse) | [ ] |
| 6.5 | Rollback automático em falha | [ ] |
| 6.6 | UI: aprovar / publicar / descartar | [ ] |
| 6.7 | Testes: publicação, rollback, isolamento na publicação | [ ] |

**Critério de saída:** cenário de aceitação completo (itens 1–15 do
pedido) executado ponta a ponta em `kit-activites-tea`.

---

## FASE 7 — Histórico, Backup, Auditoria

| ID | Item | Estado |
|---|---|---|
| 7.1 | Migration: `cms_element_versions`, `cms_audit_logs` | [ ] |
| 7.2 | Registro automático de toda escrita | [ ] |
| 7.3 | Restauração de página / imagem / projeto | [ ] |
| 7.4 | UI: histórico, publicações, backups, auditoria | [ ] |
| 7.5 | Testes: histórico, restauração, auditoria | [ ] |

---

## FASE 8 — Testes e auditoria final

| ID | Item | Estado |
|---|---|---|
| 8.1 | Suíte completa (19 cenários do pedido) | [ ] |
| 8.2 | Revisão de segurança independente | [ ] |
| 8.3 | Regressão das páginas públicas | [ ] |
| 8.4 | Performance (scanner incremental, paginação) | [ ] |
| 8.5 | Veredito final | [ ] |

---

## Pendências conhecidas (fora do escopo imediato)

| Item | Motivo |
|---|---|
| ~~`_probe.txt` em produção~~ | ✅ removido (2026-11-16) |
| ~~`painel` symlink não versionado~~ | ✅ removido; `/painel` agora é redirect 301 para `/admin` |
| ~~3 WebP não versionados em `img/cms/`~~ | ✅ versionados (2026-11-16) |
| ~~`admin/tools/error_log` público~~ | ✅ removido + bloqueio na FASE 1 |
| `member_users` vazia | área de membros sem uso |
| ~~`index.html.bak.*` em produção~~ | ✅ removidos (2026-11-16) |

---

## Correções de exposição (2026-11-16)

Auditoria pós-FASE 2 encontrou dados internos servidos sem sessão.
Corrigido em três camadas, com teste dedicado (`ExposureGuardTest`).

| Achado | Antes | Depois |
|---|---|---|
| `/admin/content.json` | 200 · 24 KB públicos | 403 |
| `/admin/site-map.json` | 200 · 91 KB públicos | 403 |
| `/admin/api/content.php` | 200 · conteúdo completo sem sessão | 401 |
| `/painel/*` (symlink) | 200 · furava todas as regras | 301 → `/admin` |
| `/_probe.txt` | 200 | 403 + removido |
| `index.html.bak.*` | 200 | 403 + removidos |

**Causa raiz do `/painel`:** o symlink apontava para `admin/`, mas o
Apache aplica `RewriteRule` sobre a URL pedida, não sobre o destino do
link. Nenhuma regra `^admin/...` valia para `/painel/...`. Substituir o
symlink por redirect elimina a classe inteira do problema.

**Causa raiz do `content.php`:** `admin_require_auth()` liberava acesso
quando `require_pin` era `false`. Agora a sessão é sempre obrigatória
para dados internos; `require_pin => false` apenas dispensa o PIN no
login, não a sessão.

**Bug adicional encontrado:** tabela `cms_content` ausente causava
`PDOException` não tratada, devolvendo HTML com o caminho absoluto do
servidor. Corrigido com `try/catch` no carregamento e handler global que
converte qualquer excepção em JSON genérico (`display_errors=0`).
