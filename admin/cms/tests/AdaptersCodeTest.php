<?php
declare(strict_types=1);

/**
 * Adaptadores PHP, CSS, JSON e Legacy.
 *
 * O ponto central: PHP e CSS declaram honestamente que NÃO sabem
 * escrever, e por isso os elementos caem para read_only. É melhor
 * bloquear do que corromper um ficheiro de código.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Registry\Adapters\CssAdapter;
use FazendoEmCasa\Cms\Registry\Adapters\JsonAdapter;
use FazendoEmCasa\Cms\Registry\Adapters\LegacyAdapter;
use FazendoEmCasa\Cms\Registry\Adapters\PhpAdapter;
use FazendoEmCasa\Cms\Registry\Editability;

$t = new TestRunner();

/* ---------------- PHP ---------------- */

$php = new PhpAdapter();

$t->group('PhpAdapter — honestidade');
$t->same('nome', 'php', $php->name());
$cap = $php->capability();
$t->ok('NÃO declara escrita', $cap['canWrite'] === false);
$t->ok('declara limitação', $cap['limitation'] !== null);
$t->ok('limitação menciona FASE 6', str_contains((string) $cap['limitation'], 'FASE 6'));

$t->group('PhpAdapter — tudo cai para read_only');
$analysis = ['elements' => [
    ['cms_id' => 'php-literal-0', 'preview' => 'Bem-vindo'],
    ['cms_id' => '', 'preview' => 'Outro'],
]];
$els = $php->extract($analysis);
$t->same('2 elementos', 2, count($els));
$niveis = array_unique(array_map(fn ($e) => $e->editability, $els));
$t->same('todos read_only', [Editability::READ_ONLY], array_values($niveis));
$t->ok('todos com motivo', array_reduce($els, fn ($c, $e) => $c && trim($e->editabilityReason) !== '', true));
$t->ok('nenhum gravável', array_reduce($els, fn ($c, $e) => $c && !$e->isWritable(), true));

/* ---------------- CSS ---------------- */

$css = new CssAdapter();

$t->group('CssAdapter — honestidade');
$t->same('nome', 'css', $css->name());
$t->ok('NÃO declara escrita', $css->capability()['canWrite'] === false);

$t->group('CssAdapter — URLs viram read_only');
$els = $css->extract(['links' => [
    ['href' => '../img/bg.png'],
    ['href' => '/x.jpg'],
    ['href' => ''],
]]);
$t->same('2 URLs (ignora vazia)', 2, count($els));
$t->same('tipo background', 'background', $els[0]->type);
$t->same('valor é a URL', '../img/bg.png', $els[0]->value);
$t->same('selector é url()', 'url(../img/bg.png)', $els[0]->selector);
$t->same('read_only', Editability::READ_ONLY, $els[0]->editability);

/* ---------------- JSON ---------------- */

$json = new JsonAdapter();

$t->group('JsonAdapter — escrita real');
$t->same('nome', 'json', $json->name());
$t->ok('declara escrita', $json->capability()['canWrite'] === true);

$t->group('JsonAdapter — extração de chaves');
$els = $json->extract(['json_data' => [
    'hero' => ['title' => 'Olá', 'subtitle' => 'Mundo'],
    'preco' => 14.9,
    'ativo' => true,
    'aninhado' => ['a' => ['b' => 'fundo']],
]]);
$t->same('5 escalares', 5, count($els));
$paths = array_map(fn ($e) => $e->selector, $els);
$t->ok('achou hero.title', in_array('hero.title', $paths, true));
$t->ok('achou hero.subtitle', in_array('hero.subtitle', $paths, true));
$t->ok('achou aninhado.a.b', in_array('aninhado.a.b', $paths, true));
$t->ok('achou preco', in_array('preco', $paths, true));
$t->ok('achou ativo', in_array('ativo', $paths, true));

$byPath = [];
foreach ($els as $e) {
    $byPath[$e->selector] = $e;
}
$t->same('hero.title é editable', Editability::EDITABLE, $byPath['hero.title']->editability);
$t->same('valor de hero.title', 'Olá', $byPath['hero.title']->value);
$t->same('json_path guardado', 'hero.title', $byPath['hero.title']->meta['json_path']);

$t->group('JsonAdapter — sem dados');
$t->same('sem json_data devolve vazio', [], $json->extract([]));

/* ---------------- Legacy ---------------- */

$legacy = new LegacyAdapter();

$t->group('LegacyAdapter — contrato');
$t->same('nome', 'legacy', $legacy->name());
$t->ok('declara escrita (delegada)', $legacy->capability()['canWrite'] === true);
$t->ok('estratégia menciona delegação', str_contains($legacy->capability()['writeStrategy'], 'legado'));

$t->group('LegacyAdapter — importa campos do site-map');
$els = $legacy->extract(['legacy_fields' => [
    ['id' => 'seo.title', 'selector' => 'title', 'type' => 'text', 'value' => 'Título'],
    ['id' => 'hero.img', 'selector' => '#hero img', 'type' => 'image', 'value' => 'a.webp'],
    ['id' => 'sem.selector', 'selector' => '', 'type' => 'text', 'value' => 'x'],
    ['id' => 'tipo.ruim', 'selector' => '#x', 'type' => 'inventado', 'value' => 'x'],
]]);
$t->same('2 campos válidos', 2, count($els));
$t->same('text vira paragraph', 'paragraph', $els[0]->type);
$t->same('image vira image', 'image', $els[1]->type);
$t->same('legacy_id preservado', 'seo.title', $els[0]->meta['legacy_id']);
$t->same('editable', Editability::EDITABLE, $els[0]->editability);

$t->group('LegacyAdapter — sem campos');
$t->same('sem legacy_fields devolve vazio', [], $legacy->extract([]));

exit($t->summary());
