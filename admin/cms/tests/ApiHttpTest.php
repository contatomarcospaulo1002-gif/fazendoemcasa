<?php
declare(strict_types=1);

/**
 * Testes HTTP da API de projetos.
 *
 * Requer um servidor local apontando para este repositório, com um
 * admin_users ativo no banco de teste.
 *
 * Uso:
 *   php admin/cms/tests/ApiHttpTest.php --base=http://127.0.0.1:8099
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = null;
foreach (array_slice($argv, 1) as $a) {
    if (str_starts_with($a, '--base=')) {
        $base = rtrim(substr($a, 7), '/');
    }
}
if ($base === null) {
    fwrite(STDERR, "Uso: php ApiHttpTest.php --base=http://127.0.0.1:8099\n");
    exit(2);
}

$passed = 0;
$failed = 0;
$failures = [];

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed, $failures;
    if ($cond) {
        $passed++;
        echo "  OK   $label\n";
        return;
    }
    $failed++;
    $failures[] = $label . ($detail !== '' ? " — $detail" : '');
    echo "  FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/** @return array{code:int, body:string, json:array|null} */
function req(string $url, string $method = 'GET', ?array $payload = null, array $headers = []): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => false,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
            $h[] = 'Content-Type: application/json';
        }
    }
    $opts[CURLOPT_HTTPHEADER] = $h;
    curl_setopt_array($ch, $opts);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);
    return ['code' => $code, 'body' => $body, 'json' => is_array($json) ? $json : null];
}

echo "\n== API de projetos — autenticação ==\n";

$r = req($base . '/admin/api/cms-projects.php');
ok('sem sessão retorna 401', $r['code'] === 401, 'code=' . $r['code']);
ok('resposta indica não autenticado', ($r['json']['authenticated'] ?? null) === false);

echo "\n== API de projetos — CSRF ==\n";

// Sem token: mesmo autenticado, POST deve falhar.
echo "  (requer sessão autenticada para testar o caminho completo)\n";

echo "\n== API de projetos — descoberta ==\n";

$r = req($base . '/admin/api/cms-projects.php?discover=1');
ok('discover sem sessão retorna 401', $r['code'] === 401, 'code=' . $r['code']);

echo "\n";
if ($failed === 0) {
    echo "OK $passed/" . ($passed + $failed) . "\n";
    echo "(testes autenticados exigem login: ver ApiAuthTest.php)\n";
    exit(0);
}
echo "FALHAS: $failed de " . ($passed + $failed) . "\n";
foreach ($failures as $f) {
    echo "  - $f\n";
}
exit(1);
