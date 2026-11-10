# Docker — ambiente de teste local

## Início rápido (Windows / Mac / Linux)

Na raiz do projeto:

```bash
copy docker\.env.example docker\.env
docker compose up -d --build
```

Aguardar o MySQL ficar healthy (~30 s), depois:

```bash
docker compose exec web php admin/tools/migrate-json-to-db.php
```

Abrir: **http://localhost:8080/admin/**

PIN: ver `admin/config.local.php` ou `config.example.php` (com `require_pin => false` em local).

## Parar

```bash
docker compose down
```

Dados MySQL persistem no volume `fazendoemcasa_db`.

## Portas

| Serviço | Host |
|---------|------|
| Site + admin | 8080 |
| MySQL | interno (container `db`) |
