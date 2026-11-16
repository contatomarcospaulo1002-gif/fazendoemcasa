<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * FASE 2 — Scanner de páginas.
 *
 * Cria o inventário de páginas e o registro de varreduras.
 * Nenhuma tabela pré-existente é alterada.
 */
return [
    'up' => static function (): void {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_pages (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                rel_path VARCHAR(255) NOT NULL,
                kind ENUM(\'html\',\'php\',\'css\',\'js\',\'json\',\'other\') NOT NULL DEFAULT \'html\',
                title VARCHAR(255) NULL,
                content_hash CHAR(64) NULL,
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                element_count INT UNSIGNED NOT NULL DEFAULT 0,
                image_count INT UNSIGNED NOT NULL DEFAULT 0,
                link_count INT UNSIGNED NOT NULL DEFAULT 0,
                is_entrypoint TINYINT(1) NOT NULL DEFAULT 0,
                status ENUM(\'ok\',\'error\',\'skipped\') NOT NULL DEFAULT \'ok\',
                error_message VARCHAR(500) NULL,
                last_scan_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_page (project_id, rel_path),
                INDEX idx_page_project (project_id),
                INDEX idx_page_kind (project_id, kind),
                CONSTRAINT fk_page_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_scans (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                started_at DATETIME NOT NULL,
                finished_at DATETIME NULL,
                status ENUM(\'running\',\'ok\',\'error\') NOT NULL DEFAULT \'running\',
                pages_found INT UNSIGNED NOT NULL DEFAULT 0,
                pages_changed INT UNSIGNED NOT NULL DEFAULT 0,
                pages_removed INT UNSIGNED NOT NULL DEFAULT 0,
                error_message VARCHAR(500) NULL,
                INDEX idx_scan_project (project_id, started_at),
                CONSTRAINT fk_scan_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },

    'down' => static function (): void {
        Db::exec('DROP TABLE IF EXISTS cms_scans');
        Db::exec('DROP TABLE IF EXISTS cms_pages');
    },
];
