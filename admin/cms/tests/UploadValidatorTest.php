<?php
declare(strict_types=1);

/**
 * UploadValidator: o tipo vem do CONTEÚDO, nunca do nome.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Media\UploadValidator;

$t = new TestRunner();
$v = new UploadValidator();

$tmp = sys_get_temp_dir() . '/cms-up-' . bin2hex(random_bytes(4));
mkdir($tmp, 0777, true);

/** Cria um PNG 1x1 real. */
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents($tmp . '/real.png', $png);

/** Cria um GIF 1x1 real. */
$gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
file_put_contents($tmp . '/real.gif', $gif);

/** PHP disfarçado de PNG. */
file_put_contents($tmp . '/evil.png', '<?php system($_GET["c"]); ?>');

/** Texto puro disfarçado de JPG. */
file_put_contents($tmp . '/fake.jpg', 'isto e apenas texto, nao uma imagem');

/** SVG com script. */
file_put_contents($tmp . '/evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

/** SVG com handler de evento. */
file_put_contents($tmp . '/onload.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><rect/></svg>');

/** SVG com XXE. */
file_put_contents($tmp . '/xxe.svg', '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><svg>&xxe;</svg>');

/** SVG com onload espaçado (evadiu a versão anterior). */
file_put_contents($tmp . '/onload-space.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload = "alert(1)"><rect/></svg>');

/** SVG com namespace xlink javascript:. */
file_put_contents($tmp . '/xlink-js.svg', '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>');

/** SVG com foreignObject. */
file_put_contents($tmp . '/foreign.svg', '<svg xmlns="http://www.w3.org/2000/svg"><foreignObject><div>x</div></foreignObject></svg>');

/** SVG com elemento desconhecido. */
file_put_contents($tmp . '/weird.svg', '<svg xmlns="http://www.w3.org/2000/svg"><bleh attr="x"/></svg>');

/** SVG limpo. */
file_put_contents($tmp . '/clean.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');

/** Ficheiro vazio. */
file_put_contents($tmp . '/empty.png', '');

$t->group('Upload — PNG real');
$r = $v->validate($tmp . '/real.png', 'foto.png');
$t->ok('aceite', $r['ok']);
$t->same('mime real', 'image/png', $r['mime']);
$t->same('extensão derivada', 'png', $r['extension']);
$t->same('dimensões lidas', 1, $r['width']);

$t->group('Upload — GIF real');
$r = $v->validate($tmp . '/real.gif', 'anim.gif');
$t->ok('aceite', $r['ok']);
$t->same('mime gif', 'image/gif', $r['mime']);

$t->group('Upload — PHP disfarçado de PNG');
$r = $v->validate($tmp . '/evil.png', 'foto.png');
$t->ok('REJEITADO', !$r['ok']);
$t->ok('erro menciona tipo', str_contains((string) $r['error'], 'Tipo'));

$t->group('Upload — texto disfarçado de JPG');
$r = $v->validate($tmp . '/fake.jpg', 'foto.jpg');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — extensão não decide o tipo');
// Um PNG chamado .php continua a ser aceite como PNG, mas a extensão
// de destino é derivada do MIME — nunca do nome.
$r = $v->validate($tmp . '/real.png', 'malicioso.php');
$t->ok('aceite como imagem', $r['ok']);
$t->same('extensão é png, não php', 'png', $r['extension']);

$t->group('Upload — SVG com script');
$r = $v->validate($tmp . '/evil.svg', 'logo.svg');
$t->ok('REJEITADO', !$r['ok']);
$t->ok('erro menciona executável', str_contains((string) $r['error'], 'execut'));

$t->group('Upload — SVG com onload');
$r = $v->validate($tmp . '/onload.svg', 'logo.svg');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — SVG com XXE');
$r = $v->validate($tmp . '/xxe.svg', 'logo.svg');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — SVG limpo');
$r = $v->validate($tmp . '/clean.svg', 'logo.svg');
$t->ok('aceite', $r['ok']);
$t->same('mime svg', 'image/svg+xml', $r['mime']);

$t->group('Upload — SVG onload espaçado (regressão do achado de segurança)');
$r = $v->validate($tmp . '/onload-space.svg', 'logo.svg');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — SVG com xlink javascript:');
$r = $v->validate($tmp . '/xlink-js.svg', 'logo.svg');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — SVG com foreignObject');
$r = $v->validate($tmp . '/foreign.svg', 'logo.svg');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — SVG com elemento desconhecido');
$r = $v->validate($tmp . '/weird.svg', 'logo.svg');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — ficheiro vazio');
$r = $v->validate($tmp . '/empty.png', 'vazio.png');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — ficheiro inexistente');
$r = $v->validate($tmp . '/nao-existe.png', 'x.png');
$t->ok('REJEITADO', !$r['ok']);

$t->group('Upload — limite de tamanho');
$t->same('limite é 8 MB', 8 * 1024 * 1024, UploadValidator::MAX_BYTES);

$t->group('Upload — tipos permitidos');
$t->ok('jpeg permitido', isset(UploadValidator::ALLOWED['image/jpeg']));
$t->ok('webp permitido', isset(UploadValidator::ALLOWED['image/webp']));
$t->ok('php NÃO permitido', !in_array('php', UploadValidator::ALLOWED, true));
$t->ok('html NÃO permitido', !isset(UploadValidator::ALLOWED['text/html']));

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

exit($t->summary());
