<?php
declare(strict_types=1);

/**
 * Runner de migrations.
 *
 * Uso:
 *   php admin/cms/bin/migrate.php            # aplica pendentes
 *   php admin/cms/bin/migrate.php --status   # lista estado
 *   php admin/cms/bin/migrate.php --rollback=1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;

if (!Db::connected()) {
    fwrite(STDERR, "Base de dados indisponível. Verifica admin/config.local.php\n");
    exit(1);
}

$migrator = new Migrator(dirname(__DIR__) . '/Migrations');

$args = array_slice($argv, 1);

if (in_array('--status', $args, true)) {
    $applied = $migrator->applied();
    $pending = $migrator->pending();
    echo "Aplicadas (" . count($applied) . "):\n";
    foreach ($applied as $m) {
        echo "  [x] $m\n";
    }
    echo "Pendentes (" . count($pending) . "):\n";
    foreach ($pending as $m) {
        echo "  [ ] $m\n";
    }
    exit(0);
}

foreach ($args as $arg) {
    if (str_starts_with($arg, '--rollback=')) {
        $steps = max(1, (int) substr($arg, 11));
        $undone = $migrator->rollback($steps);
        if ($undone === []) {
            echo "Nada para reverter.\n";
            exit(0);
        }
        foreach ($undone as $m) {
            echo "Revertida: $m\n";
        }
        exit(0);
    }
}

$done = $migrator->migrate();
if ($done === []) {
    echo "Nada pendente.\n";
    exit(0);
}
foreach ($done as $m) {
    echo "Aplicada: $m\n";
}
echo "OK " . count($done) . " migration(s).\n";
