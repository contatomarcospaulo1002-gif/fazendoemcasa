<?php
/**
 * Copia para config.local.php e altera credenciais.
 * config.local.php não vai para o Git.
 *
 * Produção (MySQL): cria administrador com
 *   php admin/tools/create-admin-user.php
 * O PIN só é usado se não houver contas em admin_users.
 *
 * Laragon sem MySQL: 'require_pin' => false
 */
return [
    'pin' => '2828',
    'require_pin' => false,
  /** Ativar MySQL (VPS ou Docker). No GitHub Pages deixa false. */
    'use_database' => false,
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'fazendoemcasa',
        'user' => 'fazendoemcasa',
        'pass' => '',
    ],
];
