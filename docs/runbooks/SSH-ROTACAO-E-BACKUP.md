# Runbook: rotação SSH, backup e rollback

Executar **antes** de tratar deploys de rotina. Não colar chaves ou senhas neste ficheiro.

## 1. Backup

1. MySQL: dump da base do CMS (nome no `admin/config.local.php` do servidor).
2. Ficheiros: tarball do document root (excluir `admin/config.local.php` do repositório Git; incluir no backup cifrado local).
3. Anotar `git log -1 --oneline` no servidor **antes** de cada deploy.

## 2. Rotacionar chave de deploy

1. Gerar par OpenSSH **só para fazendoemcasa**.
2. Adicionar chave pública em `authorized_keys` do utilizador de deploy.
3. Atualizar secret `DEPLOY_SSH_KEY` no GitHub (repo fazendoemcasa).
4. Remover chave antiga associada a este deploy.
5. Testar: Actions → **Deploy produção** → `workflow_dispatch`.
6. Se a mesma chave antiga servia outro projeto, rodar lá também.

## 3. Rollback de código

```bash
cd /home/elbasoftcom/fazendoemcasa_www
sudo -u elbasoftcom git log -3 --oneline
sudo -u elbasoftcom git reset --hard <commit_anterior>
```

## 4. Cloudflare (S10)

- SSL/TLS → **Always Use HTTPS**: ON
- HSTS: ativar só depois de confirmar HTTPS em todo o domínio
- Headers adicionais: parte já coberta pelo `.htaccess` na raiz do site
