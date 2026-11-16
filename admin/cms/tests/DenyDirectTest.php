<?php
declare(strict_types=1);

/**
 * Testa a defesa em profundidade do código interno do CMS.
 *
 * Verifica:
 *  1. Todo arquivo interno tem a guarda _deny-direct.
 *  2. O .htaccess da pasta existe e nega acesso.
 *  3. A raiz bloqueia ^admin/cms/.
 *  4. Via HTTP, os arquivos respondem 404 (quando --base é dado).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

$t = new TestRunner();
$root = dirname(__DIR__);

$t->group('Guarda — arquivos internos');

$missing = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($rel, 'bin/') || str_starts_with($rel, 'tests/')) {
        continue;
    }
    if (in_array($rel, ['bootstrap.php', '_deny-direct.php'], true)) {
        continue;
    }
    $content = (string) file_get_contents($file->getPathname());
    if (!str_contains($content, '_deny-direct.php')) {
        $missing[] = $rel;
    }
}
$t->ok('todos os arquivos internos têm guarda', $missing === [], implode(', ', $missing));

$t->group('Guarda — .htaccess da pasta');

$htaccess = $root . '/.htaccess';
$t->ok('.htaccess existe em admin/cms', is_file($htaccess));
if (is_file($htaccess)) {
    $h = (string) file_get_contents($htaccess);
    $t->ok('nega acesso (Require all denied)', str_contains($h, 'Require all denied'));
    $t->ok('tem fallback Apache 2.2', str_contains($h, 'Deny from all'));
}

$t->group('Guarda — regra na raiz');

$rootHtaccess = dirname($root, 2) . '/.htaccess';
$t->ok('.htaccess da raiz existe', is_file($rootHtaccess));
if (is_file($rootHtaccess)) {
    $h = (string) file_get_contents($rootHtaccess);
    $t->ok('bloqueia ^admin/cms/', str_contains($h, '^admin/cms/'));
    $t->ok('bloqueia ^admin/tools/', str_contains($h, '^admin/tools/'));
}

$t->group('Guarda — execução direta simulada');

// Simula um request HTTP direto ao arquivo, sem bootstrap.
// Escreve um probe temporário (evita problemas de escaping no -r).
$probe = $root . '/Core/PathGuard.php';
$probeScript = sys_get_temp_dir() . '/cms-probe-' . bin2hex(random_bytes(4)) . '.php';
file_put_contents(
    $probeScript,
    "<?php\n"
    . '$_SERVER["SCRIPT_FILENAME"] = ' . var_export($probe, true) . ";\n"
    . '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
    . 'include ' . var_export($probe, true) . ";\n"
);
$out = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeScript) . ' 2>&1', $out, $code);
@unlink($probeScript);
$output = implode("\n", $out);
$t->ok('execução direta responde Not Found', str_contains($output, 'Not Found'), substr($output, 0, 160));

$t->group('Guarda — HTTP real');

$base = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--base=')) {
        $base = rtrim(substr($a, 7), '/');
    }
}
if ($base === null) {
    echo "  SKIP (passe --base=http://127.0.0.1:8099 para testar via HTTP)\n";
} else {
    $paths = [
        '/admin/cms/Core/PathGuard.php',
        '/admin/cms/Projects/ProjectRepository.php',
        '/admin/cms/views/central.php',
        '/admin/cms/Migrations/20261116120000_phase1_projects.php',
        '/admin/cms/_deny-direct.php',
    ];
    foreach ($paths as $p) {
        $ch = curl_init($base . $p);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $t->ok("$p responde 404", $code === 404, 'code=' . $code);
        $t->ok("$p não vaza código", !str_contains($body, 'class PathGuard') && !str_contains($body, '<?php'));
    }
}

exit($t->summary());
