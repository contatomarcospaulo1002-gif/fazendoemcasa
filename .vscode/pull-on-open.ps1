# Puxa origin ao abrir a pasta. Se a arvore tiver alteracao rastreada, so faz fetch.
$ErrorActionPreference = 'Continue'

git fetch origin
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Fetch falhou (sem rede ou remote).' -ForegroundColor Red
    exit 1
}

$dirty = git status --porcelain --untracked-files=no
if ($dirty) {
    Write-Host 'Arvore suja: so fetch (sem pull). Commite ou descarte antes de puxar.' -ForegroundColor Yellow
    exit 0
}

git pull --ff-only
if ($LASTEXITCODE -ne 0) {
    Write-Host 'Pull falhou (divergiu ou sem rede).' -ForegroundColor Red
    exit 1
}

Write-Host 'Repo atualizado com origin.' -ForegroundColor Green
exit 0
