# Ativa http://fazendoemcasa.test/admin (painel com PHP)
# Execute no PowerShell como Administrador, ou crie o virtual host pelo menu do Laragon.

$vhostPath = "C:\laragon\etc\apache2\sites-enabled\auto.fazendoemcasa.test.conf"
$vhost = @"
<VirtualHost *:80> 
    DocumentRoot "C:/laragon/www/fazendoemcasa"
    ServerName fazendoemcasa.test
    ServerAlias *.fazendoemcasa.test
    <Directory "C:/laragon/www/fazendoemcasa">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@

if (-not (Test-Path $vhostPath)) {
    Set-Content -Path $vhostPath -Value $vhost -Encoding UTF8
    Write-Host "Virtual host criado: $vhostPath"
} else {
    Write-Host "Virtual host ja existe."
}

$hostsFile = "$env:SystemRoot\System32\drivers\etc\hosts"
$line = "127.0.0.1 fazendoemcasa.test"
$hosts = Get-Content $hostsFile -ErrorAction Stop
if ($hosts -notmatch "fazendoemcasa\.test") {
    Add-Content -Path $hostsFile -Value $line
    Write-Host "Entrada adicionada ao hosts."
} else {
    Write-Host "hosts ja tem fazendoemcasa.test"
}

Write-Host ""
Write-Host "Reinicia o Apache no Laragon (Stop All -> Start All)."
Write-Host "Abre: http://fazendoemcasa.test/admin/"
Write-Host "Deves ver 'Laragon (PHP) - podes gravar fotos' no topo."
