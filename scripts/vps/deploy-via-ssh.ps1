# Deploy do projeto para VPS via SSH (a partir do Windows)
# Uso:
#   $env:VPS_HOST = "203.0.113.10"
#   $env:VPS_USER = "root"
#   $env:VPS_PATH = "/var/www/fazendoemcasa"
#   .\scripts\vps\deploy-via-ssh.ps1

param(
    [string]$Host = $env:VPS_HOST,
    [string]$User = $env:VPS_USER,
    [string]$RemotePath = $env:VPS_PATH
)

if (-not $Host -or -not $User -or -not $RemotePath) {
    Write-Host "Define VPS_HOST, VPS_USER e VPS_PATH (ou parametros -Host -User -RemotePath)"
    exit 1
}

$root = Split-Path (Split-Path $PSScriptRoot -Parent) -Parent
if (-not (Test-Path "$root\index.html")) {
    $root = "c:\laragon\www\fazendoemcasa"
}

Write-Host "A enviar ficheiros para ${User}@${Host}:${RemotePath} ..."
ssh "${User}@${Host}" "mkdir -p '$RemotePath'"
scp -r "$root\admin" "$root\index.html" "$root\style.css" "$root\img" "$root\video" "$root\scripts" `
    "${User}@${Host}:${RemotePath}/"

Write-Host "A correr migracao JSON -> MySQL (se PHP existir na VPS)..."
ssh "${User}@${Host}" "cd '$RemotePath' && php admin/tools/migrate-json-to-db.php"
Write-Host "Concluido. Confirma admin/config.local.php na VPS."
