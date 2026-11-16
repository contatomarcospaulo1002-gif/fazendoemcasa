<?php
declare(strict_types=1);

/**
 * Testes de isolamento e path traversal — o núcleo da segurança
 * multi-tenant. Se estes falharem, nada mais importa.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\PathException;
use FazendoEmCasa\Cms\Core\PathGuard;

$t = new TestRunner();

// Fixture temporária isolada.
$base = sys_get_temp_dir() . '/cms-test-' . bin2hex(random_bytes(4));
mkdir($base, 0777, true);
mkdir($base . '/proj-a', 0777, true);
mkdir($base . '/proj-b', 0777, true);
mkdir($base . '/proj-a/assets', 0777, true);
file_put_contents($base . '/proj-a/index.html', '<h1>A</h1>');
file_put_contents($base . '/proj-b/index.html', '<h1>B</h1>');
file_put_contents($base . '/proj-a/assets/a.webp', 'x');

$outside = sys_get_temp_dir() . '/cms-outside-' . bin2hex(random_bytes(4));
mkdir($outside, 0777, true);
file_put_contents($outside . '/secret.txt', 'segredo');

$guard = new PathGuard($base);

$t->group('PathGuard — caminhos válidos');

$t->same('resolveDirectory proj-a', realpath($base . '/proj-a'), $guard->resolveDirectory('proj-a'));
$t->same('resolveFile index.html', realpath($base . '/proj-a/index.html'), $guard->resolveFile('proj-a/index.html'));
$t->same('sanitize remove ./', 'proj-a/index.html', $guard->sanitizeRelative('./proj-a/index.html'));
$t->same('sanitize normaliza barras', 'proj-a/assets/a.webp', $guard->sanitizeRelative('proj-a\\assets\\a.webp'));

$t->group('PathGuard — travessia de diretório');

$t->throws('rejeita ..', fn () => $guard->sanitizeRelative('../etc/passwd'), PathException::class);
$t->throws('rejeita .. no meio', fn () => $guard->sanitizeRelative('proj-a/../../etc'), PathException::class);
$t->throws('rejeita .. codificado', fn () => $guard->sanitizeRelative('%2e%2e/etc'), PathException::class);
$t->throws('rejeita .. duplo codificado', fn () => $guard->sanitizeRelative('%252e%252e/etc'), PathException::class);
$t->throws('rejeita caminho absoluto unix', fn () => $guard->sanitizeRelative('/etc/passwd'), PathException::class);
$t->throws('rejeita letra de unidade', fn () => $guard->sanitizeRelative('C:/Windows'), PathException::class);
$t->throws('rejeita UNC', fn () => $guard->sanitizeRelative('//servidor/share'), PathException::class);
$t->throws('rejeita byte nulo', fn () => $guard->sanitizeRelative("proj-a/index.html\0.txt"), PathException::class);
$t->throws('rejeita vazio', fn () => $guard->sanitizeRelative('   '), PathException::class);
$t->throws('rejeita só pontos', fn () => $guard->sanitizeRelative('./././'), PathException::class);

$t->group('PathGuard — escape por symlink');

$linkPath = $base . '/escape-link';
$linked = @symlink($outside, $linkPath);
if ($linked) {
    $t->throws(
        'symlink para fora é rejeitado',
        fn () => $guard->resolveDirectory('escape-link'),
        PathException::class
    );
} else {
    echo "  SKIP symlink (sem permissão para criar)\n";
}

$t->group('PathGuard — arquivo inexistente');

$t->throws('resolveFile de inexistente', fn () => $guard->resolveFile('proj-a/nao-existe.html'), PathException::class);
$t->throws('resolveDirectory de inexistente', fn () => $guard->resolveDirectory('nao-existe'), PathException::class);
$t->throws('resolveFile de diretório', fn () => $guard->resolveFile('proj-a'), PathException::class);

$t->group('PathGuard — escrita');

$writePath = $guard->resolveForWrite('proj-a/novo.html');
$t->ok('resolveForWrite aceita arquivo novo', str_ends_with($writePath, 'novo.html'));
$t->throws('resolveForWrite rejeita ..', fn () => $guard->resolveForWrite('../fora.html'), PathException::class);
$t->throws('resolveForWrite rejeita dir inexistente', fn () => $guard->resolveForWrite('proj-a/sub/novo.html'), PathException::class);

$t->group('PathGuard — isolamento entre projetos');

$t->ok('proj-a e proj-b são árvores distintas', !$guard->sameTree('proj-a', 'proj-b'));
$t->ok('proj-a é a mesma árvore que si', $guard->sameTree('proj-a', 'proj-a'));
$t->ok('proj-a/assets está dentro de proj-a', $guard->sameTree('proj-a', 'proj-a/assets'));

// Limpeza.
@unlink($base . '/escape-link');
@unlink($base . '/proj-a/assets/a.webp');
@unlink($base . '/proj-a/index.html');
@unlink($base . '/proj-b/index.html');
@unlink($outside . '/secret.txt');
@rmdir($base . '/proj-a/assets');
@rmdir($base . '/proj-a');
@rmdir($base . '/proj-b');
@rmdir($base);
@rmdir($outside);

exit($t->summary());
