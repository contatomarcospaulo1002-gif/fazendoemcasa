# Smoke read-only em producao (auditoria S1/S2/S4)
$ErrorActionPreference = 'Continue'
$base = 'https://fazendoemcasa.com'

$checks = @(
  @{ Path = '/.git/HEAD'; Expect = 'not200' },
  @{ Path = '/docs/INFRA_VPS.md'; Expect = 'not200' },
  @{ Path = '/scripts/deploy.ps1'; Expect = 'not200' },
  @{ Path = '/admin/api/'; Expect = 'noindex' },
  @{ Path = '/admin/api/session.php'; Expect = 'json' },
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
