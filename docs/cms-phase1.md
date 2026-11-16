# FASE 1 — Central de Projetos

Concluída em 2026-11-16. Branch `feat/cms-central`.

## O que existe agora

### Estrutura

```
admin/
  cms-ui.php              ← entrypoint público da UI (autentica e inclui a view)
  api/cms-projects.php    ← entrypoint público da API (autentica, delega)
  assets/cms.css|js       ← front-end (única pasta servível)
  cms/                    ← TODO o código interno (bloqueado na web)
    bootstrap.php         ← autoloader + constantes + requerimentos base
    _deny-direct.php      ← guarda anti-execução direta (defesa em profundidade)
    .htaccess             ← Require all denied
    Core/
      Config.php          ← config + raiz autorizada + exclusões
      Db.php              ← PDO singleton, prepared statements reais
      PathGuard.php       ← ISOLAMENTO: resolve e valida todo caminho
      PathException.php
      Json.php            ← JSON com erros explícitos
      Csrf.php            ← token por sessão, hash_equals
      Auth.php            ← sessão admin (compatível com painel legado)
      Http.php            ← respostas JSON + parsing de body
      Migrator.php        ← runner versionado com up/down
    Projects/
      ProjectRepository.php  ← CRUD de projetos
      ProjectLookup.php      ← interface mínima (testável sem banco)
      DiscoveryEngine.php    ← varre a raiz e sugere projetos
      ProjectException.php
    Migrations/
      20261116120000_phase1_projects.php
    views/central.php     ← markup da Central
    bin/migrate.php       ← CLI: migrate / --status / --rollback=N
    tests/                ← suíte completa (124 asserções)
```

### Modelo de dados (só tabelas novas)

| Tabela | Papel |
|---|---|
| `cms_migrations` | controle de migrations aplicadas |
| `cms_projects` | projeto: slug, name, rel_path, base_url, status |
| `cms_project_settings` | chave/valor por projeto |
| `cms_users_permissions` | user × projeto × papel (owner/editor/viewer) |

Nenhuma tabela pré-existente foi alterada.

### Segurança implementada

| Controle | Onde | Como |
|---|---|---|
| Isolamento de caminho | `PathGuard` | `realpath` + prefixo + rejeita `..`, nulo, absoluto, UNC, URL-encode, symlink de escape |
| Caminho nunca vem do request | `ProjectRepository` | `rel_path` só do banco; `project_id` forjado → 404/403 |
| CSRF | `Csrf` | token de 64 hex por sessão, rotacionado no login, header `X-CSRF-Token` ou campo `_csrf` |
| Código interno inacessível | 3 camadas | `.htaccess` da raiz, `.htaccess` da pasta, guarda `_deny-direct` em 15 arquivos |
| SQL injection | `Db` | `ATTR_EMULATE_PREPARES = false` |
| Sessão | `Auth` | `HttpOnly`, `SameSite=Strict`, `Secure` quando HTTPS, `session_regenerate_id` no login |

### Testes — 124 asserções

```
PathGuardTest.php .............. 23/23   travessia, symlink, colisões
security-tools-guard.php ....... 25/25   guarda de admin/tools (fase 1.0)
DenyDirectTest.php ............. 18/18   código interno inacessível
DiscoveryEngineTest.php ........ 19/19   descoberta e exclusões
ProjectRepositoryTest.php ...... 22/22   CRUD, unicidade, isolamento
ApiHttpTest.php ................  3/3    sem sessão → 401
ApiAuthTest.php ................ 14/14   login, CSRF, traversal, isolamento
                               ─────
                               124/124
```

Como rodar:

```powershell
# banco de teste isolado
$env:DB_HOST='127.0.0.1'; $env:DB_DATABASE='fec_cms_test'
$env:DB_USERNAME='fec_test'; $env:DB_PASSWORD='fec_test_pw'
$env:CMS_USE_DATABASE='1'; $env:CMS_PROJECTS_ROOT="$env:TEMP\cms-api-fixture"

php -S 127.0.0.1:8099 -t .
php admin/cms/tests/run.php --base=http://127.0.0.1:8099
```

O `ProjectRepositoryTest` **recusa rodar** se `DB_DATABASE` não contiver `test`.

## Bugs reais encontrados pelos testes

| # | Bug | Impacto | Correção |
|---|---|---|---|
| 1 | `readonly` promovido + reatribuição no construtor | fatal error no `PathGuard` | propriedade não promovida |
| 2 | `$data['discovery']` sem `??` | warning + `Data truncated` | operador de coalescência |
| 3 | `hidden` como seletor de elemento no CSS | modal sempre visível | `[hidden]` |
| 4 | `.is-disabled` compartilhada entre nav e card | projeto desativado não podia ser reativado | `.cms-nav .is-disabled` + `.cms-project.is-off` |
| 5 | `ProjectRepository` `final` impedia dublê de teste | teste de discovery acoplado ao banco | interface `ProjectLookup` |
| 6 | `Auth::check()` ignorava sessão PIN legada | usuário logado via PIN era expulso | aceita `admin_ok` |
| 7 | PowerShell corrompeu UTF-8 dos assets | "Sem descri??o" | arquivos reescritos/corrigidos |

## Como aplicar no servidor

```bash
ssh elbafood-prod
cd /home/elbasoftcom/fazendoemcasa_www
sudo -u elbasoftcom git fetch && sudo -u elbasoftcom git reset --hard origin/main
php admin/cms/bin/migrate.php --status
php admin/cms/bin/migrate.php
```

Depois: `https://fazendoemcasa.com/admin/cms-ui.php`.

## Limitações conhecidas desta fase

- O card mostra "Páginas" e "Elementos" como `—`: os dados vêm da FASE 2.
- "Gerir" está desabilitado até a FASE 4.
- Metade do menu lateral (Histórico, Publicações, Backups, Auditoria)
  está desabilitada até as FASES 6–7.
- `cms_users_permissions` existe e é respeitada, mas ainda não há tela
  para gerir permissões (FASE 7).
- O symlink de escape não pôde ser testado no Windows (sem permissão
  para criar symlink); o teste roda e cobre na VPS Linux.
