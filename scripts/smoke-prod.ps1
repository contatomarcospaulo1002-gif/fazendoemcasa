# Smoke read-only em producao (auditoria S1/S2/S4)
$ErrorActionPreference = 'Continue'
$base = 'https://fazendoemcasa.com'

$checks = @(
  @{ Path = '/.git/HEAD'; Expect = 'not200' },
  @{ Path = '/docs/INFRA_VPS.md'; Expect = 'not200' },
  @{ Path = '/scripts/deploy.ps1'; Expect = 'not200' },
  @{ Path = '/admin/api/'; Expect = 'noindex' },
  @{ Path = '/admin/api/session.php'; Expect = 'json' },
  # CMS multi-projeto: código interno nunca servido (FASE 1)
  @{ Path = '/admin/cms/bootstrap.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Core/PathGuard.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/views/central.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/bin/migrate.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Scanner/HtmlAnalyzer.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/views/project-pages.php'; Expect = 'not200' },
  @{ Path = '/admin/tools/extract-content.php'; Expect = 'not200' },
  # Dados internos do painel (pendência S1: conteúdo público sem sessão)
  @{ Path = '/admin/content.json'; Expect = 'not200' },
  @{ Path = '/admin/site-map.json'; Expect = 'not200' },
  @{ Path = '/admin/api/content.php'; Expect = 'not200' },
  # Alias legado /painel: o symlink não herda as regras do admin
  @{ Path = '/painel/content.json'; Expect = 'not200' },
  @{ Path = '/painel/config.local.php'; Expect = 'not200' },
  @{ Path = '/painel/tools/extract-content.php'; Expect = 'not200' },
  @{ Path = '/painel/cms/bootstrap.php'; Expect = 'not200' },
  # Resíduos
  @{ Path = '/_probe.txt'; Expect = 'not200' },
  @{ Path = '/index.html.bak.20261111-120708'; Expect = 'not200' },
  @{ Path = '/admin/assets/cms.css'; Expect = '200' },
  @{ Path = '/admin/assets/cms-pages.js'; Expect = '200' },
  @{ Path = '/'; Expect = '200' },
  @{ Path = '/robots.txt'; Expect = '200' },
  @{ Path = '/sitemap.xml'; Expect = '200' }
)

function Get-Status([string]$Url) {
  try {
    $r = Invoke-WebRequest -Uri $Url -Method Get -TimeoutSec 20 -UseBasicParsing
    return @{ Code = [int]$r.StatusCode; Body = $r.Content.Substring(0, [Math]::Min(500, $r.Content.Length)) }
  }
  catch {
    $code = $null
    if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode }
    return @{ Code = $code; Body = $_.Exception.Message }
  }
}

Write-Host "Smoke $base" -ForegroundColor Cyan
$fail = 0

foreach ($c in $checks) {
  $url = "$base$($c.Path)"
  $res = Get-Status $url
  $code = $res.Code
  $ok = $false
  switch ($c.Expect) {
    '200' { $ok = ($code -eq 200) }
    'not200' { $ok = ($code -ne 200) }
    'noindex' { $ok = ($res.Body -notmatch 'Index of') }
    'json' {
      $ok = ($code -eq 200 -and $res.Body -match '"authenticated"')
      if ($ok -and $res.Body -match '"useDatabase"') {
        $ok = $false
        Write-Host "  AVISO: session.php expõe useDatabase" -ForegroundColor Yellow
      }
    }
  }
  $mark = if ($ok) { 'OK' } else { 'FALHA'; $fail++ }
  $color = if ($ok) { 'Green' } else { 'Red' }
  Write-Host "  [$mark] $($c.Path) -> HTTP $code" -ForegroundColor $color
}

if ($fail -gt 0) { exit 1 }
exit 0
