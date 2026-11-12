# Deploy: merge na main, push GitHub e atualiza VPS.
# USO: .\scripts\deploy.ps1 -Branch feat/xyz [-Confirmar]
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Branch,
    [switch]$Confirmar
)

$ErrorActionPreference = 'Stop'
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$ProtectedBranch = 'main'
$SiteUrl = 'https://fazendoemcasa.com/'
$SshHost = '129.121.35.178'
$SshPort = 22022
$SshUser = 'root'
$RemotePath = '/home/elbasoftcom/fazendoemcasa_www'

function Write-Step([string]$Text) { Write-Host ''; Write-Host "==> $Text" -ForegroundColor Cyan }
function Write-Ok([string]$Text) { Write-Host "    OK: $Text" -ForegroundColor Green }
function Fail([string]$Text) { Write-Host "ERRO: $Text" -ForegroundColor Red; exit 1 }

function Invoke-Git {
    param([Parameter(Mandatory = $true)][string[]]$GitArgs, [switch]$AllowFail)
    $ErrorActionPreference = 'Continue'
    $out = & git @GitArgs 2>&1 | ForEach-Object { "$_" }
    $code = $LASTEXITCODE
    if ($code -ne 0 -and -not $AllowFail) { Fail ("git $($GitArgs -join ' ') falhou:" + [Environment]::NewLine + ($out -join [Environment]::NewLine)) }
    return [pscustomobject]@{ Output = @($out); Code = $code }
}

function Invoke-Ssh {
    param([Parameter(Mandatory = $true)][string[]]$SshArgs)
    $ErrorActionPreference = 'Continue'
    $out = & ssh @SshArgs 2>&1 | ForEach-Object { "$_" }
    return [pscustomobject]@{ Output = @($out); Code = $LASTEXITCODE }
}

if (-not (Test-Path (Join-Path $ProjectRoot '.git'))) { Fail 'Repositorio git nao encontrado.' }
Push-Location $ProjectRoot
$originalBranch = $null
try {
    $originalBranch = (Invoke-Git -GitArgs @('rev-parse', '--abbrev-ref', 'HEAD')).Output[0].Trim()
    if ($Branch -eq $ProtectedBranch) { Fail "Use uma branch diferente de $ProtectedBranch." }
    Invoke-Git -GitArgs @('rev-parse', '--verify', '--quiet', "refs/heads/$Branch") | Out-Null
    $dirty = (Invoke-Git -GitArgs @('status', '--porcelain', '--untracked-files=no')).Output
    if ($dirty.Count -gt 0) { Fail 'Ha alteracoes nao commitadas.' }
    Invoke-Git -GitArgs @('fetch', 'origin') | Out-Null
    Invoke-Git -GitArgs @('push', 'origin', $Branch) | Out-Null
    Invoke-Git -GitArgs @('checkout', $ProtectedBranch) | Out-Null
    Invoke-Git -GitArgs @('pull', '--ff-only', 'origin', $ProtectedBranch) | Out-Null
    $merge = Invoke-Git -GitArgs @('merge', '--no-ff', $Branch, '-m', "Merge branch '$Branch' into '$ProtectedBranch'") -AllowFail
    if ($merge.Code -ne 0) {
        Invoke-Git -GitArgs @('merge', '--abort') -AllowFail | Out-Null
        Fail 'Conflito no merge.'
    }
    if (-not $Confirmar) {
        if (-not [Environment]::UserInteractive -or [Console]::IsInputRedirected) { Fail 'Use -Confirmar.' }
        $answer = Read-Host 'Publicar na main e VPS? Digite SIM'
        if ($answer -cne 'SIM') { exit 0 }
    }
    Invoke-Git -GitArgs @('push', 'origin', $ProtectedBranch) | Out-Null
    Write-Step 'Deploy VPS'
    $cmd = "cd $RemotePath && sudo -u elbasoftcom git fetch origin && sudo -u elbasoftcom git reset --hard origin/main && find . -path ./.git -prune -o -user root ! -path './admin/config.local.php' -exec chown elbasoftcom:elbasoftcom {} + && sudo -u elbasoftcom git log -1 --oneline"
    $ssh = Invoke-Ssh -SshArgs @('-p', "$SshPort", "${SshUser}@${SshHost}", $cmd)
    if ($ssh.Code -ne 0) { Fail 'SSH falhou.' }
    Write-Host ($ssh.Output -join "`n")
    Write-Ok "Site: $SiteUrl"
} finally {
    if ($originalBranch) { Invoke-Git -GitArgs @('checkout', $originalBranch) -AllowFail | Out-Null }
    Pop-Location
}
