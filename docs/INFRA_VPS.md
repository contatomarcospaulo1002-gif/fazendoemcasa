# Infraestrutura: VPS, Cursor e deploy

## Situação hoje

| Item | Valor |
|------|--------|
| Site público em produção | **GitHub Pages** (push na `main`) |
| Domínio | `fazendoemcasa.com` (arquivo `CNAME` no repo) |
| VPS Elba (`129.121.35.178:22022`) | Pasta do site **ainda não** criada (só Elba Food em `/home/elbasoftcom/elbafood.com.br`) |
| SSH no seu Windows | Já configurado: host `elbafood-prod` em `~/.ssh/config` (user `root`, porta `22022`) |

O agente no Cursor **usa o mesmo SSH do seu usuário**. Não há “permissão especial” na pasta: basta a conexão SSH funcionar no PowerShell.

Teste rápido:

```powershell
ssh elbafood-prod "hostname"
```

## 1) Trabalhar no Cursor com acesso à VPS

1. **Abra a pasta do projeto** `C:\laragon\www\fazendoemcasa` como workspace no Cursor (arquivo separado do `elbafood`, se quiser regras só deste site).
2. Mantenha o alias SSH `elbafood-prod` (ou crie `Host fazendoemcasa-prod` apontando para o mesmo `HostName`/`Port`/`IdentityFile`).
3. Ao pedir algo no servidor, cite o host: *“no `elbafood-prod`, em …”* — o agente roda comandos `ssh elbafood-prod "..."` read-only ou conforme você autorizar.

Nada de colar chave privada no repositório. A chave fica só em `~/.ssh/`.

## 2) Se quiser hospedar na VPS (igual Elba Food)

Útil se o **painel PHP** (`/admin/`) tiver que rodar em produção (no Pages é só estático).

### No cPanel (conta `elbasoftcom`)

1. **Domínios** → adicionar ou apontar `fazendoemcasa.com` para um document root dedicado, por exemplo:
   - `/home/elbasoftcom/fazendoemcasa.com`
2. SSL Let’s Encrypt no domínio.
3. No servidor, clone o repo (deploy key de leitura no GitHub, como no Elba Food):

```bash
ssh elbafood-prod
su - elbasoftcom   # ou trabalhar como elbasoftcom no path
cd /home/elbasoftcom
git clone git@github.com:contatomarcospaulo1002-gif/fazendoemcasa.git fazendoemcasa.com
```

### DNS (trocar Pages → VPS)

- Remover registros que apontam para **GitHub Pages** (A/CNAME do Pages).
- Apontar `fazendoemcasa.com` (e `www` se usar) para o IP da VPS `129.121.35.178` (A ou CNAME conforme o painel DNS).
- Depois disso, pode **remover** o `CNAME` do repositório (só faz sente para Pages) ou desligar Pages em *Settings → Pages*.

### GitHub Actions (repo `fazendoemcasa`)

Em **Settings → Secrets and variables → Actions**, criar (valores iguais aos do Elba Food, se for a mesma VPS):

| Secret | Exemplo |
|--------|---------|
| `DEPLOY_SSH_KEY` | chave privada OpenSSH (a mesma do Elba Food, se autorizado) |
| `DEPLOY_HOST` | `129.121.35.178` |
| `DEPLOY_PORT` | `22022` |
| `DEPLOY_USER` | `root` |
| `DEPLOY_PATH` | `/home/elbasoftcom/fazendoemcasa.com` |

Adicionar workflow `deploy-production.yml` espelhando o Elba Food (sem `php-fpm` se for só HTML; **com** reload se houver PHP no docroot).

### Script local `scripts/deploy.ps1`

Estender com passo SSH (como `elbafood/scripts/deploy.ps1`): após push na `main`, `git fetch && git reset --hard origin/main` no `DEPLOY_PATH`, `chown elbasoftcom:elbasoftcom` nos arquivos.

## 3) Manter só GitHub Pages

Se o site continuar estático e o admin só no Laragon:

- VPS **não** publica o site; você só usa SSH para outras coisas (e-mail, outro domínio, etc.).
- Deploy continua: `git push origin main` → Pages.

## O que o Marcos precisa decidir

- **A)** Pages + admin só local → nada a criar na VPS para este site.
- **B)** Produção com PHP/admin na VPS → fazer passos da seção 2 e avisar o agente para adaptar `deploy.ps1` + workflow (com o caminho final do docroot).

Não rodar migration nem alterar DNS sem confirmação explícita.
