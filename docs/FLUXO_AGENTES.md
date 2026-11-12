# Fluxo de agentes — Fazendo em Casa

Adaptação do ciclo programador → testador → revisor.

## 1. Implementação

- Branch: `fix/<item>-<resumo>` a partir de `origin/main`
- Uma preocupação por branch (segurança, carousel, SEO, …)
- Nunca `git add .`; nunca commitar `config.local.php`

## 2. Testes (testador)

| Camada | Comando / ação |
|--------|----------------|
| Smoke produção | `.\scripts\smoke-prod.ps1` |
| Docker | `docker compose up -d` em `docker/` → `http://localhost:8080/` |
| Admin | Login, `session.php`, upload imagem de teste |
| Landing | Consola sem erros; carrosséis vazios não rebentam |

## 3. Revisão (revisor)

- Diff contra `main`: escopo mínimo, sem segredos
- Pedir **Security Review** se mudar `admin/api/*` ou `.htaccess`
- Confirmar checklist do item Sx em `docs/PLANO_CONCERTO.md`

## 4. Entrega

- Merge/deploy **só** quando o Marcos pedir
- Marcar `[x]` no plano com data e resultado do smoke pós-deploy
