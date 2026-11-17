<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * FASE 6 — correção: lock de publicação.
 *
 * Dois pedidos simultâneos na mesma página podiam publicar em
 * paralelo: ambos liam o mesmo original, ambos escreviam, e o último
 * rename vencia — perdendo silenciosamente as alterações do primeiro.
 *
 * O lock é uma linha em cms_project_settings com a chave de sessão.
 * UPDATE condicional garante exclusão mútua mesmo entre processos.
 */
return [
    'up' => static function (): void {
        // Nada estrutural: o lock usa cms_project_settings, que já existe.
        // Registo para controlo de migração.
        Db::exec(
            "INSERT IGNORE INTO cms_project_settings (project_id, setting_key, setting_value)
             SELECT id, 'publish_lock', '' FROM cms_projects"
        );
    },

    'down' => static function (): void {
        Db::exec("DELETE FROM cms_project_settings WHERE setting_key = 'publish_lock'");
    },
];
