<?php
declare(strict_types=1);

/**
 * Classifier + Editability: os quatro níveis e a regra de ouro.
 *
 * Prova que nada é promovido a `editable` sem as três evidências.
 */

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/TestRunner.php';

use FazendoEmCasa\Cms\Registry\Classification;
use FazendoEmCasa\Cms\Registry\Classifier;
use FazendoEmCasa\Cms\Registry\Editability;

$t = new TestRunner();
$c = new Classifier();

$canWrite = ['canWrite' => true, 'writeStrategy' => 'teste', 'limitation' => null];
$cannotWrite = ['canWrite' => false, 'writeStrategy' => 'teste', 'limitation' => 'sem offsets'];

$t->group('Editability — constantes');
$t->same('4 níveis', 4, count(Editability::ALL));
$t->ok('editable é válido', Editability::isValid('editable'));
$t->ok('lixo não é válido', !Editability::isValid('qualquer'));
$t->ok('editable é gravável', Editability::isWritable('editable'));
$t->ok('needs_adapter é gravável', Editability::isWritable('needs_adapter'));
$t->ok('read_only não é gravável', !Editability::isWritable('read_only'));
$t->ok('unconfigured não é gravável', !Editability::isWritable('unconfigured'));
$t->same('rótulo de editable', 'Editável', Editability::label('editable'));

$t->group('Classifier — adaptador sem escrita');
$r = $c->classify($cannotWrite, true, true);
$t->same('cai para read_only', Editability::READ_ONLY, $r->level);
$t->ok('motivo menciona o adaptador', str_contains($r->reason, 'teste'));
$t->ok('motivo inclui a limitação', str_contains($r->reason, 'sem offsets'));
$t->ok('não é gravável', !$r->isWritable());

$t->group('Classifier — sem referência estável');
$r = $c->classify($canWrite, false, true);
$t->same('cai para needs_adapter', Editability::NEEDS_ADAPTER, $r->level);
$t->ok('motivo menciona data-cms-id', str_contains($r->reason, 'data-cms-id'));
$t->ok('é gravável via adaptador', $r->isWritable());

$t->group('Classifier — valor ilegível');
$r = $c->classify($canWrite, true, false);
$t->same('cai para unconfigured', Editability::UNCONFIGURED, $r->level);
$t->ok('motivo menciona configuração', str_contains($r->reason, 'configura'));
$t->ok('não é gravável', !$r->isWritable());

$t->group('Classifier — todas as provas');
$r = $c->classify($canWrite, true, true);
$t->same('promove a editable', Editability::EDITABLE, $r->level);
$t->ok('motivo preenchido', trim($r->reason) !== '');
$t->ok('é gravável', $r->isWritable());

$t->group('Classifier — precedência');
// Sem escrita vence sobre tudo, mesmo com selector e valor.
$r = $c->classify($cannotWrite, true, true);
$t->same('sem escrita vence selector+valor', Editability::READ_ONLY, $r->level);
// Sem selector vence sobre valor ilegível.
$r = $c->classify($canWrite, false, false);
$t->same('sem selector vence valor ilegível', Editability::NEEDS_ADAPTER, $r->level);

$t->group('Classification — invariantes');
$t->throws('nível inválido é rejeitado', fn () => new Classification('inventado', 'x'), \InvalidArgumentException::class);
$t->throws('motivo vazio é rejeitado', fn () => new Classification('editable', '   '), \InvalidArgumentException::class);
$ok = new Classification('editable', 'ok');
$t->same('nível preservado', 'editable', $ok->level);

exit($t->summary());
