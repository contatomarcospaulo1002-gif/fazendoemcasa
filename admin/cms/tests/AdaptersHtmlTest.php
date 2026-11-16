<?php
declare(strict_types=1);

/**
 * Adaptadores HTML e Component: escrita determinística por data-cms-id.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Registry\Adapters\ComponentAdapter;
use FazendoEmCasa\Cms\Registry\Adapters\HtmlAdapter;
use FazendoEmCasa\Cms\Registry\Editability;

$t = new TestRunner();

$analysis = [
    'elements' => [
        ['cms_id' => 'hero-title', 'type' => 'text', 'tag' => 'h1', 'preview' => 'Brincar é aprender', 'has_src' => false],
        ['cms_id' => 'hero-img', 'type' => 'image', 'tag' => 'img', 'preview' => '/img/hero.webp', 'has_src' => true],
        ['cms_id' => 'cta', 'type' => 'link', 'tag' => 'a', 'preview' => 'Comprar', 'has_src' => true],
        ['cms_id' => '', 'type' => 'text', 'tag' => 'div', 'preview' => 'Sem marcação', 'has_src' => false],
        ['cms_id' => 'vazio', 'type' => 'text', 'tag' => 'p', 'preview' => '', 'has_src' => false],
    ],
];

/* ---------------- HTML ---------------- */

$html = new HtmlAdapter();

$t->group('HtmlAdapter — contrato');
$t->same('nome', 'html', $html->name());
$t->ok('suporta html', $html->supportsPageKind('html'));
$t->ok('não suporta php', !$html->supportsPageKind('php'));
$cap = $html->capability();
$t->ok('declara escrita', $cap['canWrite'] === true);
$t->ok('estratégia menciona data-cms-id', str_contains($cap['writeStrategy'], 'data-cms-id'));

$t->group('HtmlAdapter — extração');
$els = $html->extract($analysis);
$t->same('5 elementos extraídos', 5, count($els));

$byId = [];
foreach ($els as $e) {
    $byId[$e->cmsId ?? '(sem-id)'] = $e;
}

$t->same('hero-title é editable', Editability::EDITABLE, $byId['hero-title']->editability);
$t->same('selector do hero-title', '[data-cms-id="hero-title"]', $byId['hero-title']->selector);
$t->same('valor do hero-title', 'Brincar é aprender', $byId['hero-title']->value);
$t->same('tipo do hero-img', 'image', $byId['hero-img']->type);
$t->same('hero-img é editable', Editability::EDITABLE, $byId['hero-img']->editability);
$t->same('cta vira link', 'link', $byId['cta']->type);

$t->group('HtmlAdapter — sem data-cms-id');
$semId = $byId['(sem-id)'];
$t->same('cai para needs_adapter', Editability::NEEDS_ADAPTER, $semId->editability);
$t->ok('motivo explica', str_contains($semId->editabilityReason, 'data-cms-id'));
$t->ok('cmsId é null', $semId->cmsId === null);

$t->group('HtmlAdapter — valor vazio');
$t->same('vazio cai para unconfigured', Editability::UNCONFIGURED, $byId['vazio']->editability);

$t->group('HtmlAdapter — todos têm motivo');
$semMotivo = array_filter($els, fn ($e) => trim($e->editabilityReason) === '');
$t->same('nenhum elemento sem motivo', 0, count($semMotivo));

/* ---------------- Component ---------------- */

$comp = new ComponentAdapter();

$t->group('ComponentAdapter — contrato');
$t->same('nome', 'component', $comp->name());
$t->ok('suporta html', $comp->supportsPageKind('html'));
$t->ok('suporta php', $comp->supportsPageKind('php'));
$t->ok('não suporta css', !$comp->supportsPageKind('css'));

$t->group('ComponentAdapter — só com data-cms-id');
$cels = $comp->extract($analysis);
$t->same('4 elementos (ignora sem id)', 4, count($cels));
$ids = array_map(fn ($e) => $e->cmsId, $cels);
$t->ok('não inclui elemento sem id', !in_array(null, $ids, true));
$t->ok('inclui hero-title', in_array('hero-title', $ids, true));

$t->group('ComponentAdapter — mapeamento de tipo');
$map = [];
foreach ($cels as $e) {
    $map[$e->cmsId] = $e->type;
}
$t->same('text vira paragraph', 'paragraph', $map['hero-title']);
$t->same('image vira image', 'image', $map['hero-img']);
$t->same('link vira link', 'link', $map['cta']);

$t->group('ComponentAdapter — tipo desconhecido');
$weird = ['elements' => [['cms_id' => 'x', 'type' => 'inventado', 'tag' => 'div', 'preview' => 'oi']]];
$w = $comp->extract($weird);
$t->same('tipo desconhecido vira paragraph', 'paragraph', $w[0]->type);
$t->same('component_type preservado', 'text', $w[0]->meta['component_type']);

exit($t->summary());
