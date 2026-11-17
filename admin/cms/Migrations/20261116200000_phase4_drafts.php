<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * FASE 4 — Editor Visual.
 *
 * Rascunhos guardam apenas o DELTA (patch_json), nunca a página inteira.
 * Isso permite comparar antes/depois por elemento, descartar um campo
 * sem perder os outros e publicar em lote na FASE 6.
 *
 * Nenhuma tabela existente é alterada.
 */
return [
    'up' => static function (): void {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_drafts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                page_id BIGINT UNSIGNED NOT NULL,
                user_id BIGINT UNSIGNED NULL,
                patch_json MEDIUMTEXT NOT NULL,
                status ENUM(\'open\',\'previewed\',\'published\',\'discarded\')
                    NOT NULL DEFAULT \'open\',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                INDEX idx_draft_page (page_id, status),
                INDEX idx_draft_project (project_id, status),
                CONSTRAINT fk_draft_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE,
                CONSTRAINT fk_draft_page FOREIGN KEY (page_id)
                    REFERENCES cms_pages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },

    'down' => static function (): void {
        Db::exec('DROP TABLE IF EXISTS cms_drafts');
    },
];
