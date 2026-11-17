<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * FASE 6 — Publicação.
 *
 * `cms_backups` guarda o conteúdo ORIGINAL de cada ficheiro antes da
 * primeira escrita. É a rede de segurança: se a verificação pós-escrita
 * falhar, o backup é restaurado automaticamente.
 *
 * `cms_publications` é o registo auditável de cada tentativa —
 * bem-sucedida ou revertida.
 *
 * Nenhuma tabela existente é alterada.
 */
return [
    'up' => static function (): void {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_backups (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                page_id BIGINT UNSIGNED NULL,
                rel_path VARCHAR(500) NOT NULL,
                content MEDIUMBLOB NOT NULL,
                content_hash CHAR(64) NOT NULL,
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                reason VARCHAR(120) NOT NULL DEFAULT \'publish\',
                created_at DATETIME NOT NULL,
                INDEX idx_backup_page (page_id, created_at),
                INDEX idx_backup_project (project_id, created_at),
                CONSTRAINT fk_backup_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_publications (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                page_id BIGINT UNSIGNED NOT NULL,
                draft_id BIGINT UNSIGNED NULL,
                user_id BIGINT UNSIGNED NULL,
                status ENUM(\'applied\',\'rolled_back\',\'failed\') NOT NULL DEFAULT \'applied\',
                elements_changed INT UNSIGNED NOT NULL DEFAULT 0,
                backup_id BIGINT UNSIGNED NULL,
                hash_before CHAR(64) NULL,
                hash_after CHAR(64) NULL,
                error_message VARCHAR(500) NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_pub_page (page_id, created_at),
                INDEX idx_pub_project (project_id, created_at),
                CONSTRAINT fk_pub_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE,
                CONSTRAINT fk_pub_page FOREIGN KEY (page_id)
                    REFERENCES cms_pages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },

    'down' => static function (): void {
        Db::exec('DROP TABLE IF EXISTS cms_publications');
        Db::exec('DROP TABLE IF EXISTS cms_backups');
    },
];
