<?php
declare(strict_types=1);

/**
 * PreviewRenderer: patch cirúrgico, nada além do nó alvo.
 *
 * O ponto mais importante: o resto do HTML tem de permanecer intacto.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Editor\PreviewRenderer;

$t = new TestRunner();
$r = new PreviewRenderer();

$html = '<!DOCTYPE html><html><head><title>Loja</title></head><body>'
    . '<h1 data-cms-id="titulo">Original</h1>'
    . '<p data-cms-id="texto">Paragrafo original</p>'
    . '<img data-cms-id="foto" src="/img/a.webp" alt="A">'
    . '<a data-cms-id="cta" href="/antigo">Clique</a>'
    . '<div data-cms-id="fundo" style="color:red">Caixa</div>'
    . '<footer>Rodape intocado</footer>'
    . '</body></html>';

$elements = [
    1 => ['id' => 1, 'cms_id' => 'titulo', 'type' => 'paragraph'],
    2 => ['id' => 2, 'cms_id' => 'texto', 'type' => 'paragraph'],
    3 => ['id' => 3, 'cms_id' => 'foto', 'type' => 'image'],
    4 => ['id' => 4, 'cms_id' => 'cta', 'type' => 'link'],
    5 => ['id' => 5, 'cms_id' => 'fundo', 'type' => 'background'],
];

$t->group('PreviewRenderer — patch vazio');
$t->same('devolve o original', $html, $r->render($html, [], $elements));

$t->group('PreviewRenderer — substitui texto');
$out = $r->render($html, [1 => 'Novo titulo'], $elements);
$t->ok('contém o novo texto', str_contains($out, 'Novo titulo'));
$t->ok('não contém o antigo', !str_contains($out, '>Original<'));
$t->ok('preserva o data-cms-id', str_contains($out, 'data-cms-id="titulo"'));
$t->ok('preserva o rodapé', str_contains($out, 'Rodape intocado'));
$t->ok('preserva o doctype', str_contains($out, '<!DOCTYPE html>'));

$t->group('PreviewRenderer — não toca noutros elementos');
$t->ok('outro parágrafo intacto', str_contains($out, 'Paragrafo original'));
$t->ok('imagem intacta', str_contains($out, '/img/a.webp'));
$t->ok('link intacto', str_contains($out, 'href="/antigo"'));

$t->group('PreviewRenderer — URL de imagem');
$out = $r->render($html, [3 => '/img/nova.webp'], $elements);
$t->ok('troca o src', str_contains($out, 'src="/img/nova.webp"'));
$t->ok('remove o antigo src', !str_contains($out, 'src="/img/a.webp"'));
$t->ok('preserva o alt', str_contains($out, 'alt="A"'));

$t->group('PreviewRenderer — href de link');
$out = $r->render($html, [4 => '/novo'], $elements);
$t->ok('troca o href', str_contains($out, 'href="/novo"'));
$t->ok('preserva o texto do link', str_contains($out, 'Clique'));

$t->group('PreviewRenderer — background em style');
$out = $r->render($html, [5 => '/img/fundo.webp'], $elements);
$t->ok('adiciona background-image', str_contains($out, 'background-image:url(/img/fundo.webp)'));

$t->group('PreviewRenderer — patch múltiplo');
$out = $r->render($html, [1 => 'A', 2 => 'B', 3 => '/img/c.webp'], $elements);
$t->ok('aplicou 1', str_contains($out, '>A<'));
$t->ok('aplicou 2', str_contains($out, '>B<'));
$t->ok('aplicou 3', str_contains($out, 'src="/img/c.webp"'));

$t->group('PreviewRenderer — elemento fora do HTML');
$out = $r->render($html, [1 => 'X'], [1 => ['id' => 1, 'cms_id' => 'inexistente', 'type' => 'paragraph']]);
$t->same('devolve o original', $html, $r->render($html, [1 => 'X'], [1 => ['id' => 1, 'cms_id' => 'inexistente', 'type' => 'paragraph']]));

$t->group('PreviewRenderer — sem cms_id');
$out = $r->render($html, [1 => 'X'], [1 => ['id' => 1, 'cms_id' => null, 'type' => 'paragraph']]);
$t->same('ignora elemento sem cms_id', $html, $out);

$t->group('PreviewRenderer — id com aspas não quebra');
$weird = '<html><body><p data-cms-id="a&quot;b">Texto</p></body></html>';
$out = $r->render($weird, [1 => 'Novo'], [1 => ['id' => 1, 'cms_id' => 'a"b', 'type' => 'paragraph']]);
$t->ok('não lança exceção', is_string($out));

$t->group('PreviewRenderer — HTML inválido');
$t->same('devolve original', 'texto solto', $r->render('texto solto', [1 => 'X'], $elements));

$t->group('PreviewRenderer — esquema perigoso nunca entra no HTML');
foreach (['javascript:alert(1)', 'JavaScript:alert(1)', "java\tscript:alert(1)", 'data:text/html,x'] as $evil) {
    $out = $r->render($html, [3 => $evil], $elements);
    $t->ok('bloqueia ' . str_replace("\t", '\\t', $evil), !str_contains($out, 'javascript:') && !str_contains($out, 'data:text'));
}

$t->group('PreviewRenderer — não vaza declaração XML');
$out = $r->render($html, [1 => 'X'], $elements);
$t->ok('sem <?xml no início', !str_starts_with(trim($out), '<?xml'));

exit($t->summary());
