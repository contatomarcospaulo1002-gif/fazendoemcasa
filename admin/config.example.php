<?php
/**
 * Copia para config.local.php e altera o PIN.
 * config.local.php não vai para o Git.
 *
 * Sem PIN no Laragon: 'require_pin' => false
 */
return [
    'pin' => '2828',
    'require_pin' => true,
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
