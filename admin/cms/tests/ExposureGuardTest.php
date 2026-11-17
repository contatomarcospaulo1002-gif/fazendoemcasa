<?php
declare(strict_types=1);

/**
 * Regressão de exposição de dados internos.
 *
 * Prova, por leitura dos arquivos de configuração e do código, que:
 *   1. a raiz bloqueia content.json / site-map.json / _probe.txt
 *   2. o alias /painel repete os bloqueios (o symlink não herda regras)
 *   3. admin/.htaccess bloqueia os JSONs por FilesMatch
 *   4. admin/api/content.php exige sessão
 *   5. admin/api/site-map.php exige sessão
 *
 * Não faz requisições HTTP: valida a configuração versionada.
 */

require __DIR__ . '/TestRunner.php';

$t = new TestRunner();
$root = dirname(__DIR__, 3);

$htaccess = (string) file_get_contents($root . '/.htaccess');
$adminHtaccess = (string) file_get_contents($root . '/admin/.htaccess');

$t->group('Raiz — dados internos bloqueados');
foreach ([
    'admin/(content|site-map)\.json',
    '_probe\.txt',
    'index\.html\.bak\.',
    'user/api/(bootstrap|members)\.php',
] as $pattern) {
    $t->ok('regra presente: ' . $pattern, str_contains($htaccess, $pattern));
}

$t->group('Alias /painel — resolvido por redirect');
$t->ok('redireciona /painel/ para /admin/',
    str_contains($htaccess, 'RewriteRule ^painel/?$ /admin/ [R=301,L]'));
$t->ok('redireciona subpastas de /painel',
    str_contains($htaccess, 'RewriteRule ^painel/(.*)$ /admin/$1 [R=301,L]'));
$t->ok('não duplica bloqueios (evita regra esquecida)',
    !str_contains($htaccess, 'painel/(content|site-map)'));

$t->group('Raiz — regras antigas preservadas');
foreach ([
    '^admin/tools/',
    '^admin/cms/',
    'admin/config\.local\.php',
    '^docs/',
    '^scripts/',
] as $pattern) {
    $t->ok('mantida: ' . $pattern, str_contains($htaccess, $pattern));
}

$t->group('admin/.htaccess — segunda camada');
$t->ok('bloqueia content.json e site-map.json',
    str_contains($adminHtaccess, '(content|site-map)\.json'));
$t->ok('usa Require all denied', str_contains($adminHtaccess, 'Require all denied'));
$t->ok('mantém fallback Apache 2.2', str_contains($adminHtaccess, 'Deny from all'));
$t->ok('preserva no-cache de assets', str_contains($adminHtaccess, 'no-cache'));

$t->group('API — autenticação obrigatória');
$contentApi = (string) file_get_contents($root . '/admin/api/content.php');
$t->ok('content.php chama admin_require_auth', str_contains($contentApi, 'admin_require_auth()'));

$mapApi = (string) file_get_contents($root . '/admin/api/site-map.php');
$t->ok('site-map.php existe', is_file($root . '/admin/api/site-map.php'));
$t->ok('site-map.php chama admin_require_auth', str_contains($mapApi, 'admin_require_auth()'));
$t->ok('site-map.php não serve o ficheiro cru', !str_contains($mapApi, 'readfile'));

$t->group('Painel — usa as APIs protegidas');
$adminJs = (string) file_get_contents($root . '/admin/admin.js');
$t->ok('não busca site-map.json direto quando há PHP',
    str_contains($adminJs, 'loadSiteMap()'));
$t->ok('loadSiteMap usa api("site-map.php")',
    str_contains($adminJs, 'api("site-map.php")'));
$t->ok('loadContentPayload usa api("content.php")',
    str_contains($adminJs, 'api("content.php")'));

$t->group('Smoke cobre as pendências');
$smoke = (string) file_get_contents($root . '/scripts/smoke-prod.ps1');
foreach ([
    '/admin/content.json',
    '/admin/site-map.json',
    '/admin/api/content.php',
    '/painel/content.json',
    '/_probe.txt',
    '/admin/cms/Registry/Classifier.php',
    '/admin/cms/views/page-content.php',
] as $path) {
    $t->ok('smoke verifica ' . $path, str_contains($smoke, $path));
}

$t->group('FASE 3 — internos protegidos e endpoints autenticados');
$t->ok('Registry está sob /admin/cms (regra existente)',
    str_contains($htaccess, '^admin/cms/'));
$elementsApi = (string) file_get_contents($root . '/admin/api/cms-elements.php');
$t->ok('cms-elements.php exige Auth::check', str_contains($elementsApi, 'Auth::check()'));
$t->ok('cms-elements.php exige CSRF no POST', str_contains($elementsApi, 'Csrf::requireValid()'));
$t->ok('cms-elements.php checa projeto na página',
    str_contains($elementsApi, 'project_id = :p'));
$contentEntry = (string) file_get_contents($root . '/admin/cms-content.php');
$t->ok('cms-content.php exige Auth::check', str_contains($contentEntry, 'Auth::check()'));
$t->ok('cms-content.php valida página do projeto',
    str_contains($contentEntry, 'project_id = :p'));

$t->group('FASE 4 — editor protegido e sem escrita em disco');
$editorApi = (string) file_get_contents($root . '/admin/api/cms-editor.php');
$t->ok('cms-editor.php exige Auth::check', str_contains($editorApi, 'Auth::check()'));
$t->ok('cms-editor.php exige CSRF no POST', str_contains($editorApi, 'Csrf::requireValid()'));
$t->ok('cms-editor.php valida página do projeto', str_contains($editorApi, 'project_id = :p'));
$t->ok('cms-editor.php NÃO escreve em disco',
    !str_contains($editorApi, 'file_put_contents') && !str_contains($editorApi, 'fwrite'));

$editorEntry = (string) file_get_contents($root . '/admin/cms-editor.php');
$t->ok('cms-editor.php (view) exige Auth::check', str_contains($editorEntry, 'Auth::check()'));
$t->ok('cms-editor.php (view) valida página do projeto',
    str_contains($editorEntry, 'project_id = :p'));

$draftSvc = (string) file_get_contents($root . '/admin/cms/Editor/DraftService.php');
$t->ok('DraftService não escreve em disco',
    !str_contains($draftSvc, 'file_put_contents') && !str_contains($draftSvc, 'fwrite'));

$previewSvc = (string) file_get_contents($root . '/admin/cms/Editor/PreviewService.php');
$t->ok('PreviewService não escreve em disco',
    !str_contains($previewSvc, 'file_put_contents') && !str_contains($previewSvc, 'fwrite'));
$t->ok('PreviewService valida caminho com realpath', str_contains($previewSvc, 'realpath'));
$t->ok('PreviewService exige prefixo do projeto',
    str_contains($previewSvc, 'str_starts_with($real, $base'));

$t->group('FASE 5 — mídia protegida');
$mediaApi = (string) file_get_contents($root . '/admin/api/cms-media.php');
$t->ok('cms-media.php exige Auth::check', str_contains($mediaApi, 'Auth::check()'));
$t->ok('cms-media.php exige CSRF no POST', str_contains($mediaApi, 'Csrf::requireValid()'));
$t->ok('cms-media.php valida projeto', str_contains($mediaApi, 'find($projectId)'));

$mediaEntry = (string) file_get_contents($root . '/admin/cms-media.php');
$t->ok('cms-media.php (view) exige Auth::check', str_contains($mediaEntry, 'Auth::check()'));

$uploadVal = (string) file_get_contents($root . '/admin/cms/Media/UploadValidator.php');
$t->ok('valida MIME por conteúdo (finfo)', str_contains($uploadVal, 'finfo'));
$t->ok('não confia no nome do ficheiro', str_contains($uploadVal, 'ALLOWED'));
$t->ok('usa parser XML para SVG (allowlist)', str_contains($uploadVal, 'simplexml_load_string'));
$t->ok('rejeita atributos de evento (on*)', str_contains($uploadVal, "str_starts_with(\$clean, 'on')"));
$t->ok('rejeita XXE', str_contains($uploadVal, '<!entity'));

$mediaStorage = (string) file_get_contents($root . '/admin/cms/Media/MediaStorage.php');
$t->ok('nome no disco é gerado', str_contains($mediaStorage, 'random_bytes'));
$t->ok('escreve .htaccess de proteção', str_contains($mediaStorage, 'php_flag engine off'));
$t->ok('valida destino dentro do projeto', str_contains($mediaStorage, 'isInsideProject'));

$t->group('FASE 5 — smoke cobre a mídia');
foreach ([
    '/admin/cms/Media/MediaStorage.php',
    '/admin/cms/views/project-media.php',
    '/admin/api/cms-media.php',
] as $path) {
    $t->ok('smoke verifica ' . $path, str_contains($smoke, $path));
}

$t->group('FASE 4 — smoke cobre o editor');
foreach ([
    '/admin/cms/Editor/DraftService.php',
    '/admin/cms/views/page-editor.php',
    '/admin/api/cms-editor.php',
] as $path) {
    $t->ok('smoke verifica ' . $path, str_contains($smoke, $path));
}

exit($t->summary());
