# Configurar VPS — fazendoemcasa (PHP + MySQL)

## O que o assistente **não pode** fazer sozinho

Entrar na tua VPS exige **SSH** (IP, utilizador, chave ou senha). Não há credenciais guardadas neste projeto.

## O que **tu** ou o assistente (com SSH) fazem

### 1. Pasta do site na VPS

Exemplo Ubuntu:

```bash
sudo mkdir -p /var/www/fazendoemcasa
sudo chown -R $USER:www-data /var/www/fazendoemcasa
cd /var/www/fazendoemcasa
git clone https://github.com/contatomarcospaulo1002-gif/fazendoemcasa.git .
```

### 2. Pasta “banco” = MySQL (não é pasta de ficheiros)

Os dados ficam no **servidor MySQL** (base `fazendoemcasa`).  
Ficheiros de imagem continuam em **`img/cms/`** no disco.

```bash
mysql -u root -p < scripts/vps/01-mysql-schema.sql
# (edita a senha no SQL antes)
```

### 3. PHP 8.2

```bash
sudo apt update
sudo apt install -y nginx php8.2-fpm php8.2-mysql php8.2-gd php8.2-mbstring php8.2-xml
```

### 4. Config do admin

```bash
cp scripts/vps/02-config-local.php.example admin/config.local.php
nano admin/config.local.php   # senha igual à do MySQL
php admin/tools/migrate-json-to-db.php
```

### 5. Nginx (exemplo)

```nginx
server {
    listen 80;
    server_name fazendoemcasa.com www.fazendoemcasa.com;
    root /var/www/fazendoemcasa;
    index index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~* ^/admin/api/ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d fazendoemcasa.com -d www.fazendoemcasa.com
```

### 6. Permissões de escrita

```bash
sudo chown -R www-data:www-data /var/www/fazendoemcasa/img/cms
sudo chown -R www-data:www-data /var/www/fazendoemcasa/video/cms
sudo chmod -R g+w /var/www/fazendoemcasa/admin/content.json /var/www/fazendoemcasa/index.html
```

### 7. DNS

Aponta `fazendoemcasa.com` para o **IP da VPS** (remove ou mantém GitHub Pages só se quiseres espelho estático).

---

## Para o assistente configurar por ti

Envia **uma** destas opções (não coloques senhas no Git):

1. **IP/host** + utilizador SSH + confirma que a chave deste PC já está em `authorized_keys`, **ou**
2. Executa tu os comandos acima e diz onde falhou (log/captura).

O assistente pode então correr, a partir do teu PC:

`ssh utilizador@IP "comandos..."`
