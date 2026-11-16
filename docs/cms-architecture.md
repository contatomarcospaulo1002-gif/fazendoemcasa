# Arquitetura proposta — Central CMS multi-projeto

Base: [`cms-audit.md`](cms-audit.md). Status: **proposta, não implementada**.

## 1. Princípio central

> Cada elemento editável nasce com um **contrato declarado**.
> O CMS nunca "adivinha" como editar código arbitrário.

O erro a evitar é prometer "edita qualquer HTML". Isso não existe com
segurança. Em vez disso, existem **quatro níveis de editabilidade
declarados** (ver §4), e o usuário sempre vê em qual nível o elemento está.

## 2. Layout de diretórios

```
/admin/
  index.php              ← ponto de entrada único (roteador)
  /cms/                  ← código novo (namespace FazendoEmCasa\Cms)
    /Core/               Container, Router, Db, Config, Auth, Csrf, Guard
    /Projects/           ProjectRepository, DiscoveryEngine, Isolation
    /Scanner/            Analyzers por tecnologia
    /Registry/           ElementRegistry, Classifier
    /Adapters/           HtmlAdapter, PhpAdapter, CssAdapter, JsonAdapter
    /Editor/             DraftService, PreviewRenderer
    /Media/              MediaLibrary, ReferenceIndex
    /Publish/            Publisher, AtomicWriter
    /Backup/             BackupService, Snapshot
    /Api/                Controllers REST
  /assets/               CSS/JS do painel novo
  /views/                templates PHP
  /var/                  runtime, protegido
/admin-legacy/           ← o admin.js atual, congelado (fallback raiz)
/projetos/               ← projetos administrados
  /loja-infantil/
  /produto-digital/
```

**Decisão:** o painel novo vive em `admin/cms/` e é servido por
`admin/index.php`. O `admin/index.html` atual é renomeado para
`admin/legacy-editor.html` e continua funcionando para `index.html` da
raiz. Nada é deletado.

## 3. Modelo de dados

Todas as tabelas com prefixo `cms_` (evita colisão com
`cms_content/cms_revisions/cms_media` existentes, que **não são
alteradas**).

```sql
cms_projects (
  id            INT UNSIGNED PK AI,
  slug          VARCHAR(80)  NOT NULL UNIQUE,
  name          VARCHAR(160) NOT NULL,
  description   TEXT,
  rel_path      VARCHAR(255) NOT NULL UNIQUE,   -- relativo à raiz autorizada
  base_url      VARCHAR(255) NOT NULL,
  logo_media_id BIGINT UNSIGNED NULL,
  status        ENUM('active','disabled') DEFAULT 'active',
  discovery     ENUM('manual','discovered') DEFAULT 'manual',
  last_scan_at  DATETIME NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL
)
```

```sql
cms_pages (
  id           INT UNSIGNED PK AI,
  project_id   INT UNSIGNED NOT NULL,      -- FK cms_projects
  rel_path     VARCHAR(255) NOT NULL,      -- ex: pages/sobre.php
  url          VARCHAR(255) NOT NULL,
  title        VARCHAR(200),
  kind         ENUM('html','php','include','template') DEFAULT 'html',
  content_hash CHAR(64),                   -- sha256: detecta mudança
  last_scan_at DATETIME NULL,
  UNIQUE KEY uq_page (project_id, rel_path)
)
```

```sql
cms_elements (
  id            BIGINT PK AI,
  project_id    INT UNSIGNED NOT NULL,
  page_id       INT UNSIGNED NOT NULL,
  section       VARCHAR(120),              -- hero, oferta, rodape
  cms_id        VARCHAR(160),              -- data-cms-id quando existe
  type          ENUM('image','logo','banner','background','title',
                     'subtitle','paragraph','button','link','video',
                     'price','product','icon','html_component','metadata'),
  name          VARCHAR(200),
  selector      VARCHAR(400),              -- referência estável
  origin_path   VARCHAR(255),              -- arquivo real
  origin_line   INT UNSIGNED NULL,
  adapter       VARCHAR(40),               -- html|php|css|json|component
  editability   ENUM('editable','needs_adapter','read_only','unconfigured'),
  editability_reason VARCHAR(255),
  value_current MEDIUMTEXT,
  discovered_at DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  UNIQUE KEY uq_el (page_id, selector, type)
)
```

```sql
cms_element_versions (   -- histórico por elemento
  id, element_id, project_id, page_id,
  value_before MEDIUMTEXT, value_after MEDIUMTEXT,
  user_id, action ENUM('edit','replace_media','publish','restore'),
  created_at
)

cms_media (
  id, project_id, rel_path, filename, mime, bytes, width, height,
  category ENUM('banner','produto','logo','fundo','icone',
                'depoimento','ilustracao','outro') DEFAULT 'outro',
  sha256 CHAR(64), alt_text VARCHAR(255), uploaded_by, created_at
)

cms_media_references (
  id, media_id, element_id, project_id, page_id, reference_path,
  created_at
)

cms_drafts (
  id, project_id, page_id, user_id,
  patch_json JSON,          -- {element_id: new_value, ...}
  status ENUM('open','previewed','published','discarded'),
  created_at, updated_at
)

cms_publications (
  id, project_id, draft_id, user_id, files_changed JSON,
  backup_id, published_at
)

cms_backups (
  id, project_id, page_id NULL, kind ENUM('page','project','media','pre_publish'),
  storage ENUM('file','zip'), rel_path, bytes, sha256, created_at
)

cms_scans (
  id, project_id, kind ENUM('full','incremental'),
  status ENUM('queued','running','done','failed'),
  files_seen, files_changed, elements_found, error_text,
  started_at, finished_at
)

cms_audit_logs (
  id, project_id NULL, user_id, action, target_type, target_id,
  ip, meta_json, created_at
)

cms_users_permissions (
  id, user_id, project_id NULL,   -- NULL = global
  role ENUM('owner','editor','viewer'), created_at
)
```

### 3.1 Por que JSON `patch_json` nos rascunhos

Um rascunho guarda **apenas o delta**, não a página inteira. Isso permite:

- comparar antes/depois elemento a elemento,
- descartar um campo sem perder os outros,
- publicar em lote,
- custo de armazenamento baixo.

### 3.2 Migrations

Pasta `admin/cms/Migrations/` com arquivos
`YYYYMMDDHHMMSS_descricao.php` retornando `up()` e `down()`.
Runner `php admin/cms/bin/migrate.php`. Tabela `cms_migrations`
registra o que rodou. **Nenhuma migration toca tabelas existentes.**

## 4. Os quatro níveis de editabilidade

| Nível | Significado | Como o CMS trata |
|---|---|---|
| `editable` | Existe mecanismo seguro e determinístico | edita direto |
| `needs_adapter` | Estrutura reconhecida, mas exige adaptador específico | edita via adaptador, com aviso |
| `read_only` | Não há como alterar com segurança | exibe, bloqueia edição, explica |
| `unconfigured` | Scanner viu algo, mas não classificou | pede configuração manual |

Regra de ouro: **o nível é calculado, nunca presumido**. Se o adaptador
não consegue provar que sabe escrever de volta, o elemento cai para
`read_only` com motivo preenchido.

## 5. Scanner — análise estática, nunca execução

```
Scanner
 ├── ProjectDiscovery   (FASE 1)  → pastas com entrypoint
 └── PageDiscovery      (FASE 2)
      ├── HtmlAnalyzer   DOMDocument (ext-dom, disponível)
      ├── PhpAnalyzer    token_get_all() nativo → AST simplificada
      ├── CssAnalyzer    tokenizer próprio p/ url() e background
      ├── JsAnalyzer     regex ancorada + limites declarados
      └── JsonAnalyzer   json_decode
```

### 5.1 Por que `token_get_all` e não `nikic/php-parser`

`composer` existe na VPS, mas:

- `token_get_all()` é nativo, zero dependência, zero risco de supply chain;
- para o que precisamos (localizar strings de imagem, `echo`, `include`,
  blocos HTML inline) o tokenizer basta;
- `nikic/php-parser` fica como **opção futura** se precisarmos reescrever
  expressões PHP complexas.

**Nunca** `include`/`require`/`eval` de arquivo de projeto. O scanner abre
com `file_get_contents` e trata como texto.

### 5.2 O que o scanner extrai

| Fonte | Extração |
|---|---|
| HTML | `src`, `srcset`, `alt`, `href`, `style="background..."`, texto de `h1-h6/p/button/a`, `data-cms-*` |
| CSS | `url(...)`, `background-image`, `background:` |
| PHP | strings literais com extensão de imagem, `include`/`require`, HTML fora de `<?php` |
| JS | `"....webp"`, `'....png'`, `url:` em configs |
| JSON | chaves cujo valor parece caminho de mídia |

### 5.3 Scanner incremental

`cms_pages.content_hash` (sha256) + `filemtime`. Só reanalisa arquivo cujo
hash mudou. Varredura completa é **manual** (botão "Atualizar mapeamento"),
nunca automática ao abrir o painel.

## 6. Adaptadores

Interface única:

```php
interface Adapter {
    public function supports(Page $page): bool;
    public function scan(Page $page): array;          // Element[]
    public function read(Element $el): string;
    public function write(Element $el, string $value): WriteResult;
    public function describe(): string;
}
```

| Adaptador | Escopo | Método de escrita |
|---|---|---|
| `HtmlAdapter` | `.html` | DOMDocument + patch cirúrgico por nó |
| `PhpAdapter` | `.php` | tokenizer + substituição de string literal |
| `CssAdapter` | `.css` | substituição de `url()` por offset |
| `JsonAdapter` | `.json` | `json_decode` → altera chave → `json_encode` |
| `ComponentAdapter` | `data-cms-id` | resolve por atributo, independente de tecnologia |
| `LegacyAdapter` | `site-map.json` | ponte para o editor atual (raiz) |

`WriteResult` carrega `ok`, `bytesBefore`, `bytesAfter`, `diffSummary`,
`error`. **Toda escrita passa por `AtomicWriter`** (tmp + `rename`,
reaproveitando a lógica já validada de `admin_write_bytes`).

## 7. Isolamento — a regra que não pode falhar

```php
final class ProjectGuard {
    public function resolve(int $projectId): ProjectPath
    {
        // 1. projeto existe e está 'active'
        // 2. rel_path vem do BANCO, nunca do request
        // 3. realpath() do alvo
        // 4. realpath() da raiz autorizada
        // 5. str_starts_with(alvo, raiz . DIRECTORY_SEPARATOR)
        // 6. rejeita symlink que escape da raiz
        // 7. rejeita '..' após normalização
    }
}
```

O `project_id` do request é usado **apenas para buscar o registro**.
O caminho real vem sempre do banco. Um `project_id` forjado não muda
nada, porque o caminho não vem do cliente.

## 8. Publicação atômica

```
Draft (patch_json)
   → PreviewRenderer (renderiza em memória, sem tocar disco)
   → aprovação
   → BackupService::snapshot(page)      [obrigatório]
   → Adapter::write() por elemento      [AtomicWriter]
   → verificação pós-escrita (hash + re-parse)
   → cms_publications + cms_audit_logs
```

Se qualquer etapa falhar, o backup é restaurado automaticamente.
**Nunca** se reescreve o arquivo inteiro a partir do DOM do browser.

## 9. Segurança (detalhe em `cms-security.md`)

- CSRF token por sessão em **todo** POST.
- `admin/tools/` bloqueado por `.htaccess` **e** por `PHP_SAPI !== 'cli'` guard.
- Upload: validação MIME real + extensão forçada + `.htaccess` na pasta
  de destino com `php_flag engine off`.
- Escrita só dentro da raiz autorizada, via `ProjectGuard`.
- Scanner read-only por construção (não tem método de escrita).
- Publicador é módulo separado, com permissão própria.

## 10. O que NÃO será feito

| Desejo | Realidade |
|---|---|
| "Editar qualquer elemento de qualquer código" | impossível com segurança; será `read_only` com motivo |
| "Scanner automático ao abrir o painel" | proibido por performance; botão manual |
| "Publicar sem backup" | proibido |
| "IA publicando sozinha" | proibido; IA só sugere |
| "Reescrever `index.html` inteiro" | substituído por patch cirúrgico |

