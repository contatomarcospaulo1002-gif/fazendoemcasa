<?php
declare(strict_types=1);

/**
 * Aplica a guarda anti-execução direta em todos os arquivos de admin/cms/.
 *
 * A guarda é inserida DEPOIS do declare/namespace (require antes de
 * namespace é erro fatal em PHP).
 *
 * Uso:
 *   php admin/cms/tests/apply-deny-guard.php          # aplica
 *   php admin/cms/tests/apply-deny-guard.php --check  # só verifica
 */

$root = dirname(__DIR__);
$checkOnly = in_array('--check', $argv, true);

$skip = ['bootstrap.php', '_deny-direct.php'];
$targets = [];

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($rel, 'bin/') || str_starts_with($rel, 'tests/')) {
        continue;
    }
    if (in_array($rel, $skip, true)) {
        continue;
    }
    $targets[] = ['path' => $file->getPathname(), 'rel' => $rel];
}

$changed = 0;
$missing = 0;

foreach ($targets as $item) {
    $path = $item['path'];
    $rel = $item['rel'];
    $content = (string) file_get_contents($path);

    if (str_contains($content, '_deny-direct.php')) {
        continue;
    }
    $missing++;
    if ($checkOnly) {
        echo "FALTA guarda: $rel\n";
        continue;
    }

    $depth = substr_count($rel, '/');
    if ($depth === 0) {
        $require = "require_once __DIR__ . '/_deny-direct.php';";
    } elseif ($depth === 1) {
        $require = "require_once dirname(__DIR__) . '/_deny-direct.php';";
    } else {
        $require = "require_once dirname(__DIR__, {$depth}) . '/_deny-direct.php';";
    }

    // Ponto de inserção: fim da linha do namespace, se existir.
    if (preg_match('/^namespace\s+[^;]+;\R/m', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
        $pos = $m[0][1] + strlen($m[0][0]);
    } elseif (stripos($content, 'declare(strict_types=1);') !== false) {
        $decl = strpos($content, 'declare(strict_types=1);');
        $eol = strpos($content, "\n", $decl);
        $pos = $eol === false ? strlen($content) : $eol + 1;
    } else {
        echo "SEM ponto de inserção: $rel\n";
        continue;
    }

    $new = substr($content, 0, $pos) . "\n" . $require . "\n" . substr($content, $pos);
    file_put_contents($path, $new);
    $changed++;
}

echo $checkOnly
    ? ($missing === 0 ? "OK: todos os arquivos internos têm guarda.\n" : "Faltam $missing arquivo(s).\n")
    : "Guarda aplicada em $changed arquivo(s).\n";

exit($checkOnly && $missing > 0 ? 1 : 0);
