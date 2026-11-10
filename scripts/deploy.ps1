# Deploy: mescla a branch na main e envia ao GitHub (GitHub Pages publica automaticamente).
# USO: .\scripts\deploy.ps1 -Branch feat/xyz
#      .\scripts\deploy.ps1 -Branch feat/xyz -Confirmar
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

function Write-Step([string]$Text) {
    Write-Host ''
    Write-Host "==> $Text" -ForegroundColor Cyan
}

function Write-Ok([string]$Text) {
    Write-Host "    OK: $Text" -ForegroundColor Green
}

function Fail([string]$Text) {
    Write-Host ''
    Write-Host "ERRO: $Text" -ForegroundColor Red
    exit 1
}

function Invoke-Git {
    param(
        [Parameter(Mandatory = $true)][string[]]$GitArgs,
        [switch]$AllowFail
    )
    $ErrorActionPreference = 'Continue'
    $out = & git @GitArgs 2>&1 | ForEach-Object { "$_" }
    $code = $LASTEXITCODE
    if ($code -ne 0 -and -not $AllowFail) {
        Fail "git $($GitArgs -join ' ') falhou:`n$($out -join "`n")"
    }
    return [pscustomobject]@{ Output = @($out); Code = $code }
}

if (-not (Test-Path (Join-Path $ProjectRoot '.git'))) {
    Fail "Repositorio git nao encontrado em $ProjectRoot"
}

Push-Location $ProjectRoot

$originalBranch = $null
$mergeHead = $null
$mergeShort = $null

try {
    Write-Step "Checagens locais em $ProjectRoot"

    $originalBranch = (Invoke-Git -GitArgs @('rev-parse', '--abbrev-ref', 'HEAD')).Output[0].Trim()
    if (-not $originalBranch -or $originalBranch -eq 'HEAD') {
        Fail 'Nao foi possivel determinar a branch atual.'
    }

    if ($Branch -eq $ProtectedBranch) {
        Fail "A branch informada nao pode ser '$ProtectedBranch'."
    }

    $branchCheck = Invoke-Git -GitArgs @('rev-parse', '--verify', '--quiet', "refs/heads/$Branch") -AllowFail
    if ($branchCheck.Code -ne 0) {
        Fail "A branch '$Branch' nao existe localmente."
    }
    Write-Ok "Branch '$Branch' existe localmente."

    $dirty = (Invoke-Git -GitArgs @('status', '--porcelain', '--untracked-files=no')).Output
    if ($dirty.Count -gt 0) {
        Write-Host ($dirty -join "`n") -ForegroundColor Yellow
        Fail "Ha alteracoes nao commitadas na branch '$Branch'."
    }
    Write-Ok 'Nenhuma alteracao pendente.'

    Write-Step 'Buscando atualizacoes no remoto (origin)'
    Invoke-Git -GitArgs @('fetch', 'origin') | Out-Null
    Write-Ok 'Fetch concluido.'

    Write-Step "Enviando a branch '$Branch' para o remoto"
    Invoke-Git -GitArgs @('push', 'origin', $Branch) | Out-Null
    Write-Ok 'Remoto atualizado.'

    Write-Step "Atualizando '$ProtectedBranch' e fazendo merge"
    Invoke-Git -GitArgs @('checkout', $ProtectedBranch) | Out-Null
    Invoke-Git -GitArgs @('pull', '--ff-only', 'origin', $ProtectedBranch) | Out-Null

    $localMain = (Invoke-Git -GitArgs @('rev-parse', 'HEAD')).Output[0].Trim()
    $remoteMain = (Invoke-Git -GitArgs @('rev-parse', "origin/$ProtectedBranch")).Output[0].Trim()
    if ($localMain -ne $remoteMain) {
        Fail 'main local difere de origin/main.'
    }
    Write-Ok 'main alinhada com o remoto.'

    $before = $localMain

    $merge = Invoke-Git -GitArgs @('merge', '--no-ff', $Branch, '-m', "Merge branch '$Branch' into '$ProtectedBranch'") -AllowFail
    if ($merge.Code -ne 0) {
        Invoke-Git -GitArgs @('merge', '--abort') -AllowFail | Out-Null
        Fail "Conflito no merge de '$Branch' em '$ProtectedBranch'."
    }
    Write-Ok 'Merge concluido.'

    $mergeHead = (Invoke-Git -GitArgs @('rev-parse', 'HEAD')).Output[0].Trim()
    $mergeShort = (Invoke-Git -GitArgs @('rev-parse', '--short', 'HEAD')).Output[0].Trim()

    Write-Step 'Confirmacao'
    if (-not $Confirmar) {
        if (-not [Environment]::UserInteractive -or [Console]::IsInputRedirected) {
            Fail 'Sem console interativo. Use -Confirmar.'
        }
        $answer = Read-Host "Publicar merge $mergeShort na main? Digite SIM"
        if ($answer -cne 'SIM') {
            Write-Host 'Cancelado.'
            exit 0
        }
    }

    Write-Step "Push da main (dispara GitHub Pages)"
    Invoke-Git -GitArgs @('push', 'origin', $ProtectedBranch) | Out-Null
    Write-Ok 'Main enviada. Pages atualiza em 1-3 min.'
    Write-Host "Site: $SiteUrl" -ForegroundColor Green
}
finally {
    if ($originalBranch) {
        $cur = (Invoke-Git -GitArgs @('rev-parse', '--abbrev-ref', 'HEAD') -AllowFail).Output[0]
        if ($cur -ne $originalBranch) {
            Invoke-Git -GitArgs @('checkout', $originalBranch) -AllowFail | Out-Null
        }
    }
    Pop-Location
}

Write-Host ''
Write-Host "Branch: $Branch | Commit: $mergeHead | Pages: $SiteUrl" -ForegroundColor Cyan
