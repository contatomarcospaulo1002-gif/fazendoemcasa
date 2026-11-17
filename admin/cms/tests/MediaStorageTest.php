<?php
declare(strict_types=1);

/**
 * MediaStorage + ReferenceIndex + MediaReplacer.
 *
 * Requer banco de teste (DB_DATABASE contendo 'test').
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Core\Migrator;
use FazendoEmCasa\Cms\Core\PathGuard;
use FazendoEmCasa\Cms\Editor\DraftService;
use FazendoEmCasa\Cms\Media\MediaReplacer;
use FazendoEmCasa\Cms\Media\MediaStorage;
use FazendoEmCasa\Cms\Media\ReferenceIndex;
use FazendoEmCasa\Cms\Projects\ProjectRepository;
use FazendoEmCasa\Cms\Registry\RegistryService;
use FazendoEmCasa\Cms\Scanner\ScanService;

$t = new TestRunner();

$dbName = (string) (getenv('DB_DATABASE') ?: '');
if (!str_contains($dbName, 'test')) {
    fwrite(STDERR, "ABORTADO: DB_DATABASE deve conter 'test'.\n");
    exit(2);
}
if (!Db::connected()) {
    fwrite(STDERR, "Sem conexão com o banco de teste.\n");
    exit(2);
}

(new Migrator(dirname(__DIR__) . '/Migrations'))->migrate();
Db::exec('DELETE FROM cms_projects');

$base = sys_get_temp_dir() . '/cms-media-' . bin2hex(random_bytes(4));
mkdir($base . '/site', 0777, true);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($base . '/site/index.html', '<!DOCTYPE html><html><body>'
    . '<img data-cms-id="foto" src="uploads/a.png" alt="A">'
    . '<img data-cms-id="foto2" src="uploads/a.png" alt="B">'
    . '</body></html>');
file_put_contents($base . '/site/sobre.html', '<!DOCTYPE html><html><body>'
    . '<img data-cms-id="foto3" src="uploads/a.png" alt="C">'
    . '</body></html>');

$repo = new ProjectRepository(new PathGuard($base));
$scanner = new ScanService($repo);
$registry = new RegistryService($repo, $scanner);
$storage = new MediaStorage($repo);
$index = new ReferenceIndex();
$replacer = new MediaReplacer($index, new DraftService());

$project = $repo->create(['slug' => 'site', 'name' => 'Site', 'rel_path' => 'site']);
$pid = (int) $project['id'];
$scanner->scanProject($pid);
$registry->classifyProject($pid);

$t->group('Mídia — upload válido');
$tmpFile = $base . '/tmp-upload.png';
file_put_contents($tmpFile, $png);
$res = $storage->store($pid, $tmpFile, 'minha-foto.png', null, 'produtos');
$t->ok('guardou', $res['ok']);
$media = $res['media'];
$t->ok('tem id', (int) $media['id'] > 0);
$t->same('mime png', 'image/png', $media['mime']);
$t->same('categoria', 'produtos', $media['category']);
$t->ok('nome original preservado', $media['original_name'] === 'minha-foto.png');

$t->group('Mídia — nome no disco é gerado, não do utilizador');
$t->ok('não usa o nome enviado', !str_contains((string) $media['rel_path'], 'minha-foto'));
$t->ok('termina em .png', str_ends_with((string) $media['rel_path'], '.png'));
$t->ok('está em uploads/', str_contains((string) $media['rel_path'], 'uploads/'));

$t->group('Mídia — ficheiro existe no disco');
$abs = $base . '/site/uploads/' . basename((string) $media['rel_path']);
$t->ok('ficheiro criado', is_file($abs));
$t->same('conteúdo idêntico', $png, (string) file_get_contents($abs));

$t->group('Mídia — .htaccess de proteção');
$htaccess = $base . '/site/uploads/.htaccess';
$t->ok('criado', is_file($htaccess));
$rules = (string) file_get_contents($htaccess);
$t->ok('desativa engine php', str_contains($rules, 'php_flag engine off'));
$t->ok('bloqueia .php', str_contains($rules, 'php'));

$t->group('Mídia — upload duplicado não duplica');
$tmp2 = $base . '/tmp-dup.png';
file_put_contents($tmp2, $png);
$res2 = $storage->store($pid, $tmp2, 'outra.png');
$t->ok('ok', $res2['ok']);
$t->same('mesmo id (dedupe por checksum)', (int) $media['id'], (int) $res2['media']['id']);

$t->group('Mídia — upload inválido rejeitado');
$evil = $base . '/evil.png';
file_put_contents($evil, '<?php system("id"); ?>');
$res3 = $storage->store($pid, $evil, 'foto.png');
$t->ok('rejeitado', !$res3['ok']);
$t->ok('sem mídia', $res3['media'] === null);

$t->group('Mídia — projeto inexistente');
$res4 = $storage->store(999999, $tmpFile, 'x.png');
$t->ok('rejeitado', !$res4['ok']);

exit($t->summary());
