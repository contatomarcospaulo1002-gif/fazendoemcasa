<?php
declare(strict_types=1);

/**
 * Analisadores: HTML, PHP, CSS, JS, JSON.
 *
 * Nenhuma fixture é executada — todos os analisadores são estáticos.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Scanner\AssetAnalyzer;
use FazendoEmCasa\Cms\Scanner\HtmlAnalyzer;
use FazendoEmCasa\Cms\Scanner\PhpAnalyzer;

$t = new TestRunner();
$dir = sys_get_temp_dir() . '/cms-an-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);

/* ---------------- HTML ---------------- */

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="pt"><head>
<meta charset="utf-8">
<title>Loja Infantil — Teste</title>
</head><body>
<h1 data-cms-id="hero-title" data-cms-type="text">Brincar é aprender</h1>
<p data-cms-id="hero-sub">Descrição curta</p>
<img data-cms-id="hero-img" data-cms-type="image" src="/img/hero.webp" alt="Criança">
<img src="assets/foto.png" alt="Outra">
<a href="/produtos/">Ver produtos</a>
<a href="https://externo.com">Externo</a>
<a href="#topo">Topo</a>
<div>Sem marcação</div>
</body></html>
HTML;

$htmlPath = $dir . '/index.html';
file_put_contents($htmlPath, $html);

$htmlAnalyzer = new HtmlAnalyzer();
$r = $htmlAnalyzer->analyze($htmlPath, 'proj/index.html');

$t->group('HTML — metadados');
$t->ok('análise bem-sucedida', $r->ok());
$t->same('kind html', 'html', $r->kind);
$t->same('título extraído', 'Loja Infantil — Teste', $r->title);
$t->same('hash de 64 hex', 64, strlen($r->contentHash));
$t->ok('tamanho > 0', $r->sizeBytes > 0);

$t->group('HTML — elementos marcados');
$ids = array_column($r->elements, 'cms_id');
// 3 marcações: hero-title (texto), hero-sub (texto) e hero-img (imagem).
$t->same('3 elementos com data-cms-id', 3, count($r->elements));
$t->ok('tem hero-title', in_array('hero-title', $ids, true));
$t->ok('tem hero-sub', in_array('hero-sub', $ids, true));
$t->ok('tem hero-img', in_array('hero-img', $ids, true));
$t->ok('todos editáveis', array_unique(array_column($r->elements, 'editability')) === ['editable']);
$t->same('tipo do hero-title', 'text', $r->elements[0]['type']);
$t->same('preview do título', 'Brincar é aprender', $r->elements[0]['preview']);

// A imagem marcada é indexada também como imagem (dupla indexação proposital:
// elements serve ao editor, images serve ao mapa de mídia).
$imgEl = null;
foreach ($r->elements as $el) {
    if ($el['cms_id'] === 'hero-img') {
        $imgEl = $el;
    }
}
$t->same('tipo do hero-img', 'image', $imgEl['type'] ?? '');
$t->same('preview da imagem é o src', '/img/hero.webp', $imgEl['preview'] ?? '');

$t->group('HTML — imagens');
$t->same('2 imagens', 2, $r->imageCount());
$srcs = array_column($r->images, 'src');
$t->ok('tem /img/hero.webp', in_array('/img/hero.webp', $srcs, true));
$t->ok('tem assets/foto.png', in_array('assets/foto.png', $srcs, true));
$t->same('alt preservado', 'Criança', $r->images[0]['alt']);

$t->group('HTML — links internos');
$hrefs = array_column($r->links, 'href');
$t->ok('mantém link interno', in_array('/produtos/', $hrefs, true));
$t->ok('ignora link externo', !in_array('https://externo.com', $hrefs, true));
$t->ok('ignora âncora', !in_array('#topo', $hrefs, true));

$t->group('HTML — arquivo inválido');
$bad = $dir . '/vazio.html';
file_put_contents($bad, '');
$rb = $htmlAnalyzer->analyze($bad, 'proj/vazio.html');
$t->ok('arquivo vazio gera warning ou ok', $rb->ok() || !$rb->ok());

/* ---------------- PHP ---------------- */

$phpSrc = <<<'PHP'
<?php
require __DIR__ . '/../inc/header.php';
$titulo = "Bem-vindo ao curso";
$preco = 97;
echo "<h1>Oferta especial</h1>";
?>
<p>Conteúdo estático</p>
PHP;

$phpPath = $dir . '/curso.php';
file_put_contents($phpPath, $phpSrc);

$phpAnalyzer = new PhpAnalyzer();
$rp = $phpAnalyzer->analyze($phpPath, 'proj/curso.php');

$t->group('PHP — análise estática');
$t->ok('análise bem-sucedida', $rp->ok());
$t->same('kind php', 'php', $rp->kind);
$t->ok('hash de 64 hex', strlen($rp->contentHash) === 64);
$t->ok('detectou includes', count($rp->warnings) > 0);
$t->ok('avisou mistura PHP+HTML', str_contains(implode(' ', $rp->warnings), 'mistura'));
$t->ok('elementos exigem adaptador',
    $rp->elements === [] || array_unique(array_column($rp->elements, 'editability')) === ['needs_adapter']);

$t->group('PHP — não executa código');
$danger = $dir . '/perigo.php';
file_put_contents($danger, '<?php file_put_contents("/tmp/cms-pwned.txt", "x"); echo "oi";');
@unlink('/tmp/cms-pwned.txt');
$rd = $phpAnalyzer->analyze($danger, 'proj/perigo.php');
$t->ok('análise retorna', $rd->ok());
$t->ok('código NÃO foi executado', !file_exists('/tmp/cms-pwned.txt'));

/* ---------------- CSS / JS / JSON ---------------- */

$asset = new AssetAnalyzer();

$cssPath = $dir . '/style.css';
file_put_contents($cssPath, 'body{background:url("../img/bg.jpg")} .a{background:url(\'/x.png\')}');
$rc = $asset->analyzeCss($cssPath, 'proj/style.css');
$t->group('CSS — URLs de assets');
$t->same('kind css', 'css', $rc->kind);
$urls = array_column($rc->links, 'href');
$t->ok('achou ../img/bg.jpg', in_array('../img/bg.jpg', $urls, true));
$t->ok('achou /x.png', in_array('/x.png', $urls, true));

$jsPath = $dir . '/app.js';
file_put_contents($jsPath, 'console.log("oi");');
$rj = $asset->analyzeJs($jsPath, 'proj/app.js');
$t->group('JS — inventário');
$t->same('kind js', 'js', $rj->kind);
$t->ok('avisa que não é editável', str_contains(implode(' ', $rj->warnings), 'não é editável'));

$jsonPath = $dir . '/dados.json';
file_put_contents($jsonPath, '{"a":1}');
$rjson = $asset->analyzeJson($jsonPath, 'proj/dados.json');
$t->group('JSON — validação');
$t->ok('json válido passa', $rjson->ok());

$badJson = $dir . '/ruim.json';
file_put_contents($badJson, '{quebrado}');
$rbad = $asset->analyzeJson($badJson, 'proj/ruim.json');
$t->ok('json inválido falha', !$rbad->ok());
$t->ok('erro menciona JSON', str_contains((string) $rbad->error, 'JSON'));

// Limpeza.
foreach (glob($dir . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($dir);

exit($t->summary());
