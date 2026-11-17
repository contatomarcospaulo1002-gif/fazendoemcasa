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
  @{ Path = '/admin/cms/views/page-content.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Registry/Classifier.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Registry/Adapters/HtmlAdapter.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Editor/DraftService.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Editor/PreviewRenderer.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/views/page-editor.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Migrations/20261116200000_phase4_drafts.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Media/MediaStorage.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Media/UploadValidator.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/views/project-media.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Migrations/20261116220000_phase5_media.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Publisher/BackupService.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Publisher/AtomicWriter.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Publisher/Publisher.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Migrations/20261116240000_phase6_publications.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Migrations/20261116180000_phase3_elements.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/History/AuditLog.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/History/VersionLog.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/History/RestoreService.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/views/project-history.php'; Expect = 'not200' },
  @{ Path = '/admin/cms/Migrations/20261117000000_phase7_history.php'; Expect = 'not200' },
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
  # Bibliotecas internas da área de membros
  @{ Path = '/user/api/bootstrap.php'; Expect = 'not200' },
  @{ Path = '/user/api/members.php'; Expect = 'not200' },
  @{ Path = '/user/api/session.php'; Expect = 'json' },
  @{ Path = '/admin/assets/cms.css'; Expect = '200' },
  @{ Path = '/admin/assets/cms-pages.js'; Expect = '200' },
  @{ Path = '/admin/assets/cms-content.js'; Expect = '200' },
  @{ Path = '/admin/assets/cms-editor-core.js'; Expect = '200' },
  @{ Path = '/admin/assets/cms-editor-tree.js'; Expect = '200' },
  @{ Path = '/admin/assets/cms-editor-props.js'; Expect = '200' },
  @{ Path = '/admin/assets/cms-editor-shell.js'; Expect = '200' },
  @{ Path = '/admin/assets/cms-media-core.js'; Expect = '200' },
  @{ Path = '/admin/assets/cms-media-grid.js'; Expect = '200' },
  @{ Path = '/admin/assets/cms-history.js'; Expect = '200' },
  @{ Path = '/admin/api/cms-elements.php'; Expect = 'not200' },
  @{ Path = '/admin/api/cms-editor.php'; Expect = 'not200' },
  @{ Path = '/admin/api/cms-media.php'; Expect = 'not200' },
  @{ Path = '/admin/api/cms-publish.php'; Expect = 'not200' },
  @{ Path = '/admin/api/cms-history.php'; Expect = 'not200' },
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
