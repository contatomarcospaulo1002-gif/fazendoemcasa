<?php
declare(strict_types=1);

/**
 * Testes do DiscoveryEngine.
 *
 * Usa uma fixture temporária com estruturas típicas, incluindo pastas
 * que devem ser ignoradas.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Projects\DiscoveryEngine;

$t = new TestRunner();

$base = sys_get_temp_dir() . '/cms-disc-' . bin2hex(random_bytes(4));

$dirs = [
    'loja-infantil',
    'produto-digital',
    'landing-curso',
    'admin',
    'docs',
    'scripts',
    'docker',
    '.git',
    'node_modules',
    'sem-entrypoint',
];
foreach ($dirs as $d) {
    mkdir($base . '/' . $d, 0777, true);
}

// Projetos válidos.
file_put_contents($base . '/loja-infantil/index.html', '<h1>loja</h1>');
mkdir($base . '/loja-infantil/assets', 0777, true);
file_put_contents($base . '/loja-infantil/assets/logo.webp', 'x');
file_put_contents($base . '/loja-infantil/assets/banner.webp', 'x');

file_put_contents($base . '/produto-digital/index.php', '<?php echo "oi";');

file_put_contents($base . '/landing-curso/index.htm', '<h1>curso</h1>');

// Pastas que não devem ser descobertas.
file_put_contents($base . '/admin/index.php', '<?php // admin');
file_put_contents($base . '/docs/index.html', '<p>docs</p>');
file_put_contents($base . '/scripts/x.php', '<?php');
file_put_contents($base . '/docker/index.html', '<p>docker</p>');
file_put_contents($base . '/.git/config', 'x');
file_put_contents($base . '/node_modules/index.html', '<p>lib</p>');
file_put_contents($base . '/sem-entrypoint/readme.txt', 'nada');

$guard = new PathGuard($base);

$exclusions = ['admin', 'docs', 'scripts', 'docker', 'user', 'img', 'video', 'node_modules', 'vendor'];
$engine = new DiscoveryEngine($guard, $exclusions);

/** Repositório falso: simula um projeto já cadastrado. */
$fakeRepo = new class implements \FazendoEmCasa\Cms\Projects\ProjectLookup {
    public function findByPath(string $relPath): ?array
    {
        if ($relPath === 'produto-digital') {
            return ['id' => 42, 'rel_path' => 'produto-digital'];
        }
        return null;
    }
};

$found = $engine->scan($fakeRepo);
$paths = array_column($found, 'rel_path');

$t->group('Discovery — encontra projetos válidos');
$t->ok('encontrou loja-infantil', in_array('loja-infantil', $paths, true));
$t->ok('encontrou produto-digital', in_array('produto-digital', $paths, true));
$t->ok('encontrou landing-curso', in_array('landing-curso', $paths, true));
$t->same('total de 3 projetos', 3, count($found));

$t->group('Discovery — ignora pastas internas');
foreach (['admin', 'docs', 'scripts', 'docker', 'node_modules', 'sem-entrypoint'] as $bad) {
    $t->ok("não listou $bad", !in_array($bad, $paths, true));
}
$t->ok('não listou .git', !in_array('.git', $paths, true));

$t->group('Discovery — metadados');
$loja = null;
foreach ($found as $c) {
    if ($c['rel_path'] === 'loja-infantil') {
        $loja = $c;
    }
}
$t->ok('loja tem assets', $loja !== null && $loja['has_assets'] === true);
$t->same('loja tem 2 arquivos em assets', 2, $loja['asset_count'] ?? -1);
$t->same('loja entrypoint index.html', 'index.html', $loja['entrypoint'] ?? '');
$t->same('loja nome humanizado', 'Loja Infantil', $loja['name'] ?? '');

$t->group('Discovery — detecta já cadastrado');
$prod = null;
foreach ($found as $c) {
    if ($c['rel_path'] === 'produto-digital') {
        $prod = $c;
    }
}
$t->ok('produto-digital marcado como cadastrado', ($prod['already_registered'] ?? false) === true);
$t->same('produto-digital project_id 42', 42, $prod['project_id'] ?? null);
$t->ok('loja-infantil não cadastrado', ($loja['already_registered'] ?? true) === false);

$t->group('Discovery — entrypoint PHP');
$t->same('produto-digital usa index.php', 'index.php', $prod['entrypoint'] ?? '');

// Limpeza.
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($base);

exit($t->summary());
