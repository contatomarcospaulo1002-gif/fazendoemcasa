# Segurança — Central CMS multi-projeto

Base: [`cms-audit.md`](cms-audit.md). Este documento é normativo: o que
estiver aqui é obrigatório, não sugestão.

---

## 1. Achados abertos no CMS atual

| ID | Severidade | Achado | Estado |
|---|---|---|---|
| S1 | 🔴 Crítica | `admin/tools/*.php` executável pela web (`HTTP 200`) | ✅ **corrigido** — 3 camadas, 25/25 testes |
| S2 | 🟠 Alta | Nenhum CSRF em POST | aberto — FASE 1 |
| S3 | 🟠 Alta | `apply-index.php` aceita HTML arbitrário e grava | aberto — substituído na FASE 6 |
| S4 | 🟠 Alta | `admin/tools/error_log` acessível (403 hoje) | mitigado por S1 — mesma pasta bloqueada |
| S5 | 🟡 Média | Sem permissões por usuário (conta única) | aberto — FASE 7 |
| S6 | 🟡 Média | Upload só base64, 8 MB | aberto — FASE 5 |
| S7 | 🟢 Baixa | `_probe.txt` público | aberto |

### 1.1 S1 — corrigido em 2026-11-16

```
GET /admin/tools/extract-content.php  → 200   (antes)
"OK: /home/elbasoftcom/fazendoemcasa_www/admin/content.json"

GET /admin/tools/extract-content.php  → 404   (depois)
```

O `.htaccess` da raiz bloqueava `docs/`, `scripts/`, `docker/`, `.git`,
`.github`, `.vscode`, `admin/var/`, `config.local.php` e `index.html.bak.*`
— mas **não** `admin/tools/`.

Correção em três camadas (defesa em profundidade):

1. `.htaccess` em `admin/tools/`:
   ```apache
   Require all denied
   ```
2. Guard no topo de cada script (`admin/tools/_guard.php`):
   ```php
   if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
   ```
3. `.htaccess` da raiz:
   ```apache
   RewriteRule ^admin/tools/ - [F,L]
   RewriteRule ^admin/cms/ - [F,L]
   ```

A camada 2 protege mesmo se o `.htaccess` for ignorado (Nginx,
`AllowOverride None`). Teste: `admin/cms/tests/security-tools-guard.php`.

---

## 2. Regras obrigatórias para o código novo

### 2.1 CSRF

- Token gerado no login, guardado em `$_SESSION['csrf']`.
- Exposto por `session.php` como `csrfToken`.
- Enviado em header `X-CSRF-Token` em **todo** POST/PUT/DELETE.
- Validado com `hash_equals` antes de qualquer efeito colateral.
- Token rotacionado no login e no logout.

### 2.2 SQL

- **Somente** prepared statements com placeholders nomeados.
- Zero interpolação de string em SQL.
- `PDO::ATTR_EMULATE_PREPARES => false`.
- Toda consulta multi-tenant filtra por `project_id` — nunca opcional.

### 2.3 Caminhos (o ponto mais crítico)

```php
$root = realpath($config->projectsRoot());
$target = realpath($root . '/' . $project->relPath . '/' . $relativeFile);

if ($target === false || !str_starts_with($target, $root . DIRECTORY_SEPARATOR)) {
    throw new PathEscape('Fora da raiz autorizada');
}
```

Regras:

- `rel_path` **vem do banco**, nunca do request.
- Rejeitar `..`, `%2e%2e`, `\0`, caminho absoluto, UNC `\\`.
- Rejeitar symlink cujo `realpath` escape da raiz.
- Rejeitar se `realpath` retornar `false` (arquivo inexistente) em escrita.
- Comparação **case-sensitive** mesmo no Windows (usar `str_starts_with`
  sobre `realpath` normalizado).

### 2.4 Upload

1. Tamanho máximo checado em `$_FILES['size']` **e** após leitura.
2. MIME real por magic bytes (reaproveitar `admin_detect_mime`).
3. Extensão **forçada** pela lista branca: `webp`, `jpg`, `png`, `svg`
   (SVG só se sanitizado; caso contrário, proibido).
4. Nome gerado pelo servidor (`uuid`), nunca o nome original.
5. `.htaccess` na pasta de destino:
   ```apache
   php_flag engine off
   RemoveHandler .php .phtml .php8
   Options -ExecCGI -Indexes
   ```
6. Nunca `move_uploaded_file` para dentro de pasta com PHP executável.

### 2.5 XSS

- Saída em HTML sempre por `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- Conteúdo `html` (rico) passa por allowlist de tags/atributos antes de
  ser gravado — nunca `innerHTML` cru do usuário no arquivo do projeto.
- Preview em `iframe` com `sandbox="allow-same-origin"` sem
  `allow-scripts` quando possível.

### 2.6 Sessão

- `HttpOnly`, `Secure` (quando HTTPS), `SameSite=Strict`, `path=/`.
- Regeneração de ID no login.
- Timeout por inatividade configurável.
- Logout destrói sessão e limpa cookie.

### 2.7 Auditoria

Toda operação que escreve registra em `cms_audit_logs`:
`project_id`, `user_id`, `action`, `target_type`, `target_id`, `ip`,
`meta_json`, `created_at`. Sem exceção.

---

## 3. Separação de privilégios

| Módulo | Pode escrever? | Pode executar código? |
|---|---|---|
| Scanner | ❌ nunca | ❌ nunca |
| Registry | só no banco | ❌ |
| Editor | só em `cms_drafts` | ❌ |
| Media | só em pasta de mídia | ❌ |
| Publisher | só na raiz do projeto ativo | ❌ |
| Backup | só em `admin/var/backups` | ❌ |

O scanner é read-only **por construção**: a classe não possui método de
escrita. Não é uma questão de permissão — é ausência de API.

---

## 4. Bloqueios de servidor a manter/ampliar

`.htaccess` da raiz (existe, ampliar):

```apache
Options -Indexes
RewriteRule ^\.git - [F,L]
RewriteRule ^\.github - [F,L]
RewriteRule ^\.vscode - [F,L]
RewriteRule ^docs/ - [F,L]
RewriteRule ^scripts/ - [F,L]
RewriteRule ^docker/ - [F,L]
RewriteRule ^admin/var/ - [F,L]
RewriteRule ^admin/tools/ - [F,L]        # NOVO
RewriteRule ^admin/cms/ - [F,L]          # NOVO: código interno
RewriteRule (^|/)\.env$ - [F,L]
RewriteRule (^|/)config\.local\.php$ - [F,L]
RewriteRule ^index\.html\.bak\. - [F,L]
```

`admin/cms/` bloqueado na web significa que só `admin/index.php` e
`admin/api/*.php` são pontos de entrada. Views e classes ficam
inacessíveis diretamente.

---

## 5. Checklist antes de cada deploy

- [ ] `grep -r "csrf" ` — todo POST tem validação?
- [ ] Nenhuma query com interpolação?
- [ ] Todo caminho passa por `ProjectGuard`?
- [ ] Todo upload passa por `UploadValidator`?
- [ ] `admin/tools/` retorna 403 pela web?
- [ ] `admin/cms/` retorna 403 pela web?
- [ ] Nenhum segredo em arquivo versionado?
- [ ] `config.local.php` intacto no servidor?
- [ ] Backup feito antes do deploy?
- [ ] Smoke test das páginas públicas?
