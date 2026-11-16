# Acompanhamento — Central CMS multi-projeto

Atualizado: 2026-11-16 · Fase atual: **FASE 3 concluída**

Documentos: [auditoria](cms-audit.md) · [arquitetura](cms-architecture.md) ·
[roadmap](cms-roadmap.md) · [decisões](cms-decisions.md) ·
[segurança](cms-security.md) · [testes](cms-testing.md)

---

## Estado das fases

| Fase | Nome | Estado | Testes |
|---|---|---|---|
| 0 | Auditoria | ✅ concluída | n/a |
| 1 | Central de Projetos | ✅ concluída | 124/124 |
| 2 | Scanner | ✅ concluída | 66/66 |
| 3 | Content Registry | ✅ concluída | 137/137 |
| 4 | Editor Visual | ⬜ não iniciada | 0/4 |
| 5 | Biblioteca de Mídia | ⬜ não iniciada | 0/5 |
| 6 | Publicação | ⬜ não iniciada | 0/5 |
| 7 | Histórico / Backup | ⬜ não iniciada | 0/4 |
| 8 | Testes finais | ⬜ não iniciada | 0/5 |

---

## Auditoria de exposição — 2026-11-16

Revisão pós-FASE 2 encontrou **dados internos servidos sem sessão**.
Todos os itens fechados e cobertos por teste.

| Achado | Antes | Depois |
|---|---|---|
| `/admin/content.json` | 200 · 24 KB | **403** |
| `/admin/site-map.json` | 200 · 91 KB | **403** |
| `/admin/api/content.php` | 200 · conteúdo completo | **401** |
| `/painel/*` (symlink para admin) | 200 · furava tudo | **301 → /admin** |
| `/_probe.txt` | 200 | **403** + removido |
| `index.html.bak.*` | 200 | **403** + removidos |
| `/user/api/bootstrap.php` | 200 (0 bytes) | **403** |
| `/user/api/members.php` | 200 (0 bytes) | **403** |

**Por que o `/painel` era grave:** o Apache aplica `RewriteRule` sobre a
URL pedida, não sobre o destino do symlink. Nenhuma regra `^admin/...`
valia para `/painel/...`. Remover o symlink e usar redirect 301 elimina
a classe inteira do problema em vez de duplicar regras.

**Por que o `content.php` era grave:** `admin_require_auth()` liberava
acesso quando `require_pin` era `false`. Agora a sessão é sempre
obrigatória para dados internos; `require_pin => false` só dispensa o
PIN no login.

**Bug extra encontrado:** tabela `cms_content` ausente disparava
`PDOException` não tratada, devolvendo HTML com o **caminho absoluto do
servidor**. Corrigido com `try/catch` e handler global
(`display_errors=0`, excepções → JSON genérico).

**Estado verificado em produção (deploy `1713082`):**

```
Smoke ......................... 29/29
Suíte completa ............... 226/226
git status no servidor ....... limpo
Migrations ................... 2 aplicadas, 0 pendentes
```

---

## Item 1.0 — concluído ✅

**Objetivo:** eliminar o B1 (scripts de manutenção executáveis pela web).

**Implementação — três camadas:**

1. `admin/tools/_guard.php` — `if (PHP_SAPI !== 'cli') { 404; exit; }`
2. `admin/tools/.htaccess` — `Require all denied`
3. `.htaccess` da raiz — `RewriteRule ^admin/tools/ - [F,L]`
   (mais `^admin/cms/` já preparado para a FASE 1.1)

Guard aplicado nos **11 scripts** de `admin/tools/`.

**Testes:** `php admin/cms/tests/security-tools-guard.php --base=...`

```
Camada 1 (guard em todos os 11 scripts) .......... 12 OK
Camada 2 (.htaccess tools + raiz) ................  5 OK
Camada 3 (simulação SAPI não-CLI) ................  1 OK
Camada 4 (HTTP real, 7 alvos) ....................  7 OK
                                     TOTAL: 25/25 ✅
```

**Regressão verificada** (HTTP 200 mantido):

| URL | Antes | Depois |
|---|---|---|
| `/admin/` | 200 | 200 ✅ |
| `/admin/api/session.php` | 200 | 200 ✅ |
| `/admin/api/content.php` | 200 | 200 ✅ |
| `/` | 200 | 200 ✅ |
| `/kit-activites-tea/` | 200 | 200 ✅ |
| `/reliure-artisanale/` | 200 | 200 ✅ |
| `/privacidade.html`, `/termos.html` | 200 | 200 ✅ |
| `/admin/tools/extract-content.php` | **200 (executava!)** | **404** ✅ |

**Efeito colateral verificado:** os scripts continuam funcionando via
CLI (`php -l` limpo nos 11; mensagens de uso normais ao executar).

---

## Bloqueios abertos

| ID | Bloqueio | Severidade | Onde corrige |
|---|---|---|---|
| B1 | `admin/tools/*.php` executável pela web | 🔴 | ✅ **corrigido** (25/25) |
| B5 | Sem CSRF | 🟠 | ✅ **corrigido** (FASE 1) |
| B6 | Sem isolamento de caminho | 🔴 | ✅ **corrigido** (FASE 1.3) |
| B2 | Schema sem `project_id` | 🔴 | ✅ **corrigido** (FASE 1.2) |
| B3 | `site-map.json` único | 🔴 | ✅ **corrigido** (FASE 2) |
| B4 | Publicação reescreve HTML inteiro | 🔴 | FASE 6 |
| B7 | Upload só base64 | 🟠 | FASE 5 |
| B8 | Sem biblioteca de mídia | 🟠 | FASE 5 |
| B9 | Salvar = publicar | 🟠 | FASE 4 |
| B10 | Sem permissões | 🟠 | FASE 7 |
| B11 | Sem scanner | 🔴 | ✅ **corrigido** (FASE 2) |
| B12 | `admin.js` monolítico | 🟠 | FASE 4 |

---

## Incidente registrado

**2026-11-16 10:36** — durante a auditoria, `GET
/admin/tools/extract-content.php` retornou 200 e regravou
`admin/content.json` em produção.

- Detectado por `git status` no servidor.
- Restaurado com `git checkout -- admin/content.json`.
- MD5 conferido: `6ce9bd5ae93449e0c9d4d7d342d630c2` (igual ao HEAD).
- Nenhum outro arquivo afetado.
- Causa raiz: B1.

---

## Próximo passo

FASE 4 — Editor Visual, sobre o Registry agora pronto.

### FASE 3 — concluída (2026-11-16)

**Entrega:** registro de elementos, classificação em quatro níveis e
adaptadores de leitura por tecnologia.

| Item | Entrega |
|---|---|
| 3.1 | Migration `20261116180000_phase3_elements.php` → `cms_elements` |
| 3.2 | `Registry/ElementRegistry.php` — upsert por `(page_id, selector, type)` |
| 3.3 | `Registry/Classifier.php` + `Editability` + `Classification` |
| 3.4 | `HtmlAdapter`, `ComponentAdapter` |
| 3.5 | `PhpAdapter`, `CssAdapter`, `JsonAdapter` |
| 3.6 | `LegacyAdapter` (ponte para `site-map.json`) |
| 3.7 | UI `/admin/cms-content.php` — tabela de elementos com filtros |
| 3.8 | 137 verificações novas (unit + integração + HTTP) |

**Decisão central: honestidade dos adaptadores.** Só `html`, `component`,
`json` e `legacy` declaram `canWrite: true`. `php` e `css` declaram
`canWrite: false` e todo elemento deles cai para `read_only` com motivo
explícito. É preferível bloquear do que corromper código-fonte — a
escrita em PHP exige tokenizer com offsets confiáveis (FASE 6).

**Regra de ouro aplicada:** o `Classifier` só promove a `editable` com
três provas simultâneas — adaptador escreve, referência estável existe,
valor é legível. Falhando uma, cai um nível. O motivo nunca é vazio.

**Deduplicação:** quando `html` e `component` produzem o mesmo selector,
prevalece a classificação **mais restritiva**. Nunca a mais otimista.

**Cobertura adicional no scanner** (sem quebrar contagens existentes):

- `AnalysisResult::candidates` — elementos semânticos sem `data-cms-id`
  (`h1`–`h4`, `p`, `button`, `label`), listados à parte de `elements`.
  O Registry os marca como `needs_adapter` com selector posicional.
- `AnalysisResult::jsonData` — JSON decodificado, antes descartado.

**Bug encontrado em teste de UI e corrigido:** o ramo `GET ?page=`
de `cms-elements.php` não devolvia `csrfToken`, então o botão
"Classificar conteúdo" falhava com "Token de segurança ausente".
Coberto agora por teste HTTP.

**Verificado no navegador:** tabela com 6 elementos na `index.html`
(4 editáveis, 2 requerem adaptador), filtro por nível funcionando,
JSON com 3 chaves editáveis, CSS corretamente em somente leitura,
botão "Conteúdo" em cada linha do inventário.

| Verificação | Resultado |
|---|---|
| Suíte completa | **363/363** |
| Suíte com HTTP | **363/363** |
| Lint PHP (novos e alterados) | limpo |
| `git status` | sem resíduos |

---

## Histórico anterior — próximo passo da FASE 1

Ordem proposta dentro da FASE 1:

1. `1.0` bloqueio de `admin/tools/` — 2 arquivos, testável de imediato
2. `1.1` bootstrap + autoload
3. `1.2` migrations
4. `1.3` `ProjectGuard` + testes de path traversal
5. `1.4`–`1.5` discovery + exclusões
6. `1.6` API
7. `1.7`–`1.8` UI
8. `1.9` suíte de testes da fase
