<?php
declare(strict_types=1);

/**
 * Entrypoint público da tela de Produtos.
 * Toda a lógica e views vivem em admin/cms/ (bloqueado na web).
 */

require __DIR__ . '/cms/bootstrap.php';

$view = __DIR__ . '/cms/views/products.php';
require $view;
