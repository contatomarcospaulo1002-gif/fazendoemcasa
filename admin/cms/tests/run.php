<?php
declare(strict_types=1);

/**
 * Runner agregado dos testes do CMS.
 *
 * Uso:
 *   php admin/cms/tests/run.php
 *   php admin/cms/tests/run.php --group=security
 *   php admin/cms/tests/run.php --skip-db
 *
 * Testes que exigem banco são pulados se DB_DATABASE não contiver 'test'.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = array_slice($argv, 1);
$skipDb = in_array('--skip-db', $args, true);
$groupFilter = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--group=')) {
        $groupFilter = substr($a, 8);
    }
}

$dbName = (string) (getenv('DB_DATABASE') ?: '');
$dbReady = !$skipDb && str_contains($dbName, 'test');

$suites = [
    'security' => [
        'PathGuardTest.php',
        'security-tools-guard.php',
        'DenyDirectTest.php',
        'ExposureGuardTest.php',
    ],
    'discovery' => [
        'DiscoveryEngineTest.php',
        'AnalyzersTest.php',
        'ClassifierTest.php',
        'AdaptersHtmlTest.php',
        'AdaptersCodeTest.php',
        'PatchValidatorTest.php',
        'PreviewRendererTest.php',
        'UploadValidatorTest.php',
    ],
    'repository' => [
        'ProjectRepositoryTest.php',
        'ScannerIntegrationTest.php',
        'RegistryIntegrationTest.php',
        'EditorIntegrationTest.php',
        'MediaStorageTest.php',
        'MediaReferenceTest.php',
        'PublisherTest.php',
        'RollbackTest.php',
        'PublishLockTest.php',
        'HistoryTest.php',
    ],
];

$totalExit = 0;
$ran = 0;

// Testes HTTP só rodam se --base for informado.
$httpBase = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--base=')) {
        $httpBase = rtrim(substr($a, 7), '/');
    }
}

foreach ($suites as $group => $files) {
    if ($groupFilter !== null && $groupFilter !== $group) {
        continue;
    }
    foreach ($files as $file) {
        $path = __DIR__ . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        if ($group === 'repository' && !$dbReady) {
            echo "\n== $file ==\n  SKIP (sem banco de teste: defina DB_DATABASE com 'test')\n";
            continue;
        }
        echo "\n########## $file ##########\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path);
        if ($httpBase !== null) {
            $cmd .= ' --base=' . escapeshellarg($httpBase);
        }
        passthru($cmd, $code);
        $ran++;
        if ($code !== 0) {
            $totalExit = 1;
        }
    }
}

if ($httpBase !== null && $dbReady && ($groupFilter === null || $groupFilter === 'http')) {
    foreach (['ApiHttpTest.php', 'ApiAuthTest.php'] as $file) {
        $path = __DIR__ . '/' . $file;
        if (!is_file($path)) {
            continue;
        }
        echo "\n########## $file ##########\n";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path)
            . ' --base=' . escapeshellarg($httpBase);
        passthru($cmd, $code);
        $ran++;
        if ($code !== 0) {
            $totalExit = 1;
        }
    }
} elseif ($httpBase === null) {
    echo "\n(Testes HTTP não executados: passe --base=http://127.0.0.1:8099)\n";
}

echo "\n";
if ($ran === 0) {
    echo "Nenhuma suíte executada.\n";
    exit(1);
}
echo $totalExit === 0 ? "TODAS AS SUÍTES PASSARAM\n" : "HOUVE FALHAS\n";
exit($totalExit);
