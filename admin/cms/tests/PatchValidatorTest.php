<?php
declare(strict_types=1);

/**
 * PatchValidator: o patch é rejeitado inteiro se qualquer campo falhar.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Editor\PatchValidator;

$t = new TestRunner();
$v = new PatchValidator();

$elements = [
    1 => ['id' => 1, 'editability' => 'editable', 'type' => 'paragraph', 'selector' => 'a'],
    2 => ['id' => 2, 'editability' => 'editable', 'type' => 'image', 'selector' => 'b'],
    3 => ['id' => 3, 'editability' => 'read_only', 'type' => 'paragraph', 'selector' => 'c'],
    4 => ['id' => 4, 'editability' => 'needs_adapter', 'type' => 'link', 'selector' => 'd'],
    5 => ['id' => 5, 'editability' => 'editable', 'type' => 'button', 'selector' => 'e'],
];

$t->group('PatchValidator — patch vazio');
$r = $v->validate([], $elements);
$t->ok('rejeita vazio', !$r['ok']);
$t->same('um erro', 1, count($r['errors']));

$t->group('PatchValidator — elemento válido');
$r = $v->validate([1 => 'Novo texto'], $elements);
$t->ok('aceita texto', $r['ok']);
$t->same('valor limpo', 'Novo texto', $r['clean'][1]);

$t->group('PatchValidator — elemento inexistente');
$r = $v->validate([999 => 'x'], $elements);
$t->ok('rejeita id inexistente', !$r['ok']);
$t->ok('erro menciona o id', str_contains($r['errors'][0], '999'));

$t->group('PatchValidator — elemento read_only');
$r = $v->validate([3 => 'x'], $elements);
$t->ok('rejeita read_only', !$r['ok']);
$t->ok('erro menciona nível', str_contains($r['errors'][0], 'read_only'));

$t->group('PatchValidator — needs_adapter é gravável');
$r = $v->validate([4 => '/novo-destino'], $elements);
$t->ok('aceita needs_adapter', $r['ok']);

$t->group('PatchValidator — URL de imagem');
$r = $v->validate([2 => '/img/nova.webp'], $elements);
$t->ok('aceita caminho relativo', $r['ok']);
$r = $v->validate([2 => 'https://exemplo.test/a.png'], $elements);
$t->ok('aceita http(s)', $r['ok']);
$r = $v->validate([2 => ''], $elements);
$t->ok('rejeita URL vazia', !$r['ok']);
$r = $v->validate([2 => 'javascript:alert(1)'], $elements);
$t->ok('rejeita javascript:', !$r['ok']);
$r = $v->validate([2 => 'data:text/html;base64,xx'], $elements);
$t->ok('rejeita data:', !$r['ok']);
$r = $v->validate([2 => 'sem-barra.png'], $elements);
$t->ok('rejeita caminho sem prefixo', !$r['ok']);

$t->group('PatchValidator — ofuscação de esquema');
foreach ([
    'javascript:alert(1)',
    'JavaScript:alert(1)',
    "java\tscript:alert(1)",
    "java\nscript:alert(1)",
    ' javascript:alert(1)',
    'data:text/html,<script>',
    'vbscript:msgbox(1)',
    'file:///etc/passwd',
] as $evil) {
    $r = $v->validate([2 => $evil], $elements);
    $t->ok('rejeita ' . str_replace(["\t", "\n"], ['\\t', '\\n'], $evil), !$r['ok']);
}

$t->group('PatchValidator — limites de tamanho');
$r = $v->validate([1 => str_repeat('a', PatchValidator::MAX_TEXT + 1)], $elements);
$t->ok('rejeita texto longo', !$r['ok']);
$r = $v->validate([1 => str_repeat('a', PatchValidator::MAX_TEXT)], $elements);
$t->ok('aceita no limite', $r['ok']);
$r = $v->validate([2 => '/' . str_repeat('a', PatchValidator::MAX_URL)], $elements);
$t->ok('rejeita URL longa', !$r['ok']);

$t->group('PatchValidator — tudo ou nada');
$r = $v->validate([1 => 'ok', 3 => 'bloqueado'], $elements);
$t->ok('patch misto é rejeitado', !$r['ok']);
$t->same('nada é limpo', 0, count($r['clean']));

$t->group('PatchValidator — valor não escalar');
$r = $v->validate([1 => ['array']], $elements);
$t->ok('rejeita array', !$r['ok']);

$t->group('PatchValidator — múltiplos válidos');
$r = $v->validate([1 => 'A', 5 => 'Comprar'], $elements);
$t->ok('aceita dois', $r['ok']);
$t->same('dois limpos', 2, count($r['clean']));

exit($t->summary());
