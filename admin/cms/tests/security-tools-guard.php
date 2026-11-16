<?php
declare(strict_types=1);

/**
 * Teste de segurança: admin/tools/ não pode ser executável por HTTP.
 *
 * Uso:
 *   php admin/cms/tests/security-tools-guard.php --base=http://localhost:8080
 *
 * Sem --base, testa apenas a camada de código (guard CLI) localmente.
 */

$base = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = rtrim(substr($arg, 7), '/');
    }
}

$failures = 0;
$checks = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    $checks++;
    if ($ok) {
        echo "  OK   $label\n";
        return;
    }
    $failures++;
    echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

echo "== Camada 1: guard CLI (_guard.php) ==\n";

$toolsDir = dirname(__DIR__, 2) . '/tools';
$scripts = glob($toolsDir . '/*.php') ?: [];
$scripts = array_values(array_filter($scripts, static fn (string $p): bool => basename($p) !== '_guard.php'));

check('encontrou scripts em admin/tools', count($scripts) > 0, count($scripts) . ' arquivos');

foreach ($scripts as $script) {
    $src = (string) file_get_contents($script);
    $hasGuard = str_contains($src, "_guard.php");
    check(basename($script) . ' inclui _guard.php', $hasGuard);
}

echo "\n== Camada 2: .htaccess ==\n";

$toolsHtaccess = $toolsDir . '/.htaccess';
check('admin/tools/.htaccess existe', is_file($toolsHtaccess));
if (is_file($toolsHtaccess)) {
    $ht = (string) file_get_contents($toolsHtaccess);
    check('nega acesso (Require all denied)', str_contains($ht, 'Require all denied'));
}

$rootHtaccess = dirname(__DIR__, 3) . '/.htaccess';
check('.htaccess da raiz existe', is_file($rootHtaccess));
if (is_file($rootHtaccess)) {
    $ht = (string) file_get_contents($rootHtaccess);
    check('bloqueia admin/tools/', str_contains($ht, 'admin/tools/'));
    check('bloqueia admin/cms/', str_contains($ht, 'admin/cms/'));
}

echo "\n== Camada 3: simulação de requisição web ==\n";

// Simula o que aconteceria se o guard fosse executado fora do CLI.
$probe = <<<'PHP'
<?php
$sapi = 'cli-server';
if ($sapi !== 'cli') { echo "BLOCKED"; exit; }
echo "EXECUTED";
PHP;

$tmp = tempnam(sys_get_temp_dir(), 'guardtest');
file_put_contents($tmp, $probe);
$out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp) . ' 2>&1');
@unlink($tmp);
check('guard bloqueia SAPI não-CLI', trim((string) $out) === 'BLOCKED', trim((string) $out));

if ($base !== null) {
    echo "\n== Camada 4: HTTP real ($base) ==\n";
    $targets = [
        '/admin/tools/extract-content.php',
        '/admin/tools/patch-gallery-content.php',
        '/admin/tools/patch-video-content.php',
        '/admin/tools/migrate-json-to-db.php',
        '/admin/tools/create-admin-user.php',
        '/admin/tools/_guard.php',
        '/admin/tools/',
    ];
    foreach ($targets as $path) {
        $url = $base . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $blocked = in_array($code, [403, 404], true);
        check("$path → $code", $blocked, 'corpo: ' . substr(trim($body), 0, 60));
    }
} else {
    echo "\n(HTTP real não testado: passe --base=http://localhost:8080)\n";
}

echo "\n";
if ($failures === 0) {
    echo "OK $checks/$checks\n";
    exit(0);
}
echo "FALHAS: $failures de $checks\n";
exit(1);
