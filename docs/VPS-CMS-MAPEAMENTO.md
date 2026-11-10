# Mapeamento: CMS, base de dados, VPS e Docker

Projeto: **28 dias em casa** (`fazendoemcasa`)  
Data de referência: 2026-03-10  
Stack alvo: **PHP 8.2+**, **MySQL 8**, **Docker** (local), **VPS** (produção)

---

## 1. Situação atual

| Camada | Tecnologia | Limitação |
|--------|------------|-----------|
| Site público | GitHub Pages (`fazendoemcasa.com`) | Estático, sem PHP/MySQL |
| Conteúdo | `admin/content.json` + `index.html` | Ficheiros no Git |
| Imagens | `img/cms/*.webp` | Commit ou upload local (Laragon) |
| Painel | `admin/` (HTML + JS) | API PHP só com servidor |
| Mapa de campos | `admin/site-map.json` | ~13 grupos, dezenas de campos |
| Auth | Sessão PHP + PIN opcional | `config.local.php` |

**Problema principal:** no domínio hospedado (Pages) o utilizador não grava no servidor; o fluxo depende de descargas ou Laragon.

---

## 2. Arquitetura alvo (VPS)

```
[Browser] → [Nginx/Apache + PHP 8.2]
                ├── /           → index.html (e estáticos)
                ├── /admin/     → painel CMS
                └── /admin/api/ → upload, save, apply
                        ↓
                   [MySQL 8]
                   cms_content (JSON documento)
                   cms_revisions (histórico)
                   cms_media (registo de uploads)
                        ↓
                   [Disco] img/cms/, video/cms/
```

**GitHub Pages (opcional):** continua como espelho estático após `git push` de `index.html` + assets, ou deixa de ser a origem e passa a ser só CDN/backup.

---

## 3. Modelo de dados (MySQL)

### `cms_content` (conteúdo ativo)

| Coluna | Tipo | Notas |
|--------|------|--------|
| `id` | TINYINT PK | Sempre `1` (single-tenant por site) |
| `document` | JSON | Igual estrutura a `content.json` |
| `updated_at` | TIMESTAMP | Auto |

### `cms_revisions` (histórico)

| Coluna | Tipo |
|--------|------|
| `id` | BIGINT AI PK |
| `document` | JSON |
| `source` | VARCHAR(32) (`admin`, `migrate`, …) |
| `created_at` | TIMESTAMP |

### `cms_media` (auditoria de ficheiros)

| Coluna | Tipo |
|--------|------|
| `id` | BIGINT AI PK |
| `field_id` | VARCHAR(120) |
| `path` | VARCHAR(255) |
| `bytes` | INT |
| `mime` | VARCHAR(64) |
| `created_at` | TIMESTAMP |

**Regra:** o `site-map.json` continua no código (não na BD) — define tipos, seletores e validação.

---

## 4. API PHP (existente + nova)

| Endpoint | Método | Com BD | Sem BD |
|----------|--------|--------|--------|
| `session.php` | GET | `database: true` | `database: false` |
| `content.php` | GET | Lê MySQL | Lê `content.json` |
| `save-content.php` | POST | MySQL + espelho JSON | Só JSON |
| `upload-image.php` | POST | Disco + `cms_media` | Disco |
| `apply-index.php` | POST | `index.html` no disco | idem |
| `login.php` | POST | Sessão | Sessão |

---

## 5. Ambiente local Docker

| Serviço | Imagem / build | Porta host |
|---------|----------------|------------|
| `web` | `docker/php` (Apache + PDO + GD) | **8080** → 80 |
| `db` | `mysql:8.0` | só rede Docker (sem porta no host) |

URL admin local: **http://localhost:8080/admin/**  
Variáveis: ver `docker/.env.example`.

Comandos:

```bash
cd docker
copy .env.example .env
docker compose up -d --build
docker compose exec web php admin/tools/migrate-json-to-db.php
```

---

## 6. VPS (produção) — checklist

1. Ubuntu + Nginx (ou Apache) + PHP 8.2 (`pdo_mysql`, `gd`, `mbstring`).
2. MySQL: criar BD e utilizador (mesmo schema que `docker/mysql/init.sql`).
3. Clonar repositório em `/var/www/fazendoemcasa`.
4. `admin/config.local.php` com `'use_database' => true` e credenciais DB.
5. Correr `php admin/tools/migrate-json-to-db.php` uma vez.
6. Virtual host: `fazendoemcasa.com` → pasta do projeto; negar listagem; HTTPS (Let’s Encrypt).
7. Permissões de escrita em `admin/content.json`, `index.html`, `img/cms/`.
8. (Opcional) Cron ou webhook para export Git após edição.

---

## 7. Fluxo do editor (com BD)

1. Login (PIN se ativo).
2. `content.php` carrega documento da BD.
3. Edição → `save-content.php` → transação MySQL + revisão + `content.json`.
4. Upload → `upload-image.php` → WebP em `img/cms/` + linha em `cms_media`.
5. **Guardar e aplicar** → `apply-index.php` → `index.html` + backup `.bak.*`.

---

## 8. Projetos futuros (multi-site)

Reutilizar o mesmo padrão:

- Uma BD por site **ou** `cms_content.site_id` + vhost separado.
- Extrair pacote `admin/` + `docker/` como template PHP.
- Laravel só quando precisares de utilizadores, papéis, API REST grande — até lá PDO + JSON documento é suficiente.

**Linguagem recomendada:** **PHP 8.2 + MySQL** (Laragon, Docker, VPS baratos).

---

## 9. Ficheiros novos neste repositório

| Ficheiro | Função |
|----------|--------|
| `docker-compose.yml` | Orquestração (raiz) |
| `docker/php/Dockerfile` | PHP Apache |
| `docker/mysql/init.sql` | Schema |
| `admin/api/database.php` | PDO + helpers |
| `admin/api/content.php` | GET conteúdo |
| `admin/tools/migrate-json-to-db.php` | Import inicial |
| `docker/.env.example` | Credenciais |

---

## 10. Riscos e mitigação

| Risco | Mitigação |
|-------|-----------|
| JSON > 1 MB na BD | MEDIUMTEXT se necessário; fotos não vão no JSON (só paths) |
| Pages + VPS em paralelo | Definir uma fonte de verdade (VPS) |
| Backup | `cms_revisions` + backups MySQL + Git |
| Segurança admin | HTTPS, PIN, rate limit no login (futuro) |

---

## 11. Próximos passos sugeridos

1. Validar Docker local (8080) e upload de foto.
2. Provisionar MySQL na VPS e apontar DNS para VPS (ou subdomínio `app.`).
3. Migrar produção: deploy PHP; desativar admin em Pages ou redirecionar para VPS.
4. Automatizar export estático para Pages (opcional).
