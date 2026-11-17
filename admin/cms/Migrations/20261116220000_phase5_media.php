<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * FASE 5 — Biblioteca de Mídia por projeto.
 *
 * `cms_media` guarda o ficheiro enviado. `cms_media_references` guarda
 * onde cada mídia é usada, para que substituir uma imagem mostre o
 * alcance real antes de confirmar.
 *
 * Nenhuma tabela existente é alterada.
 */
return [
    'up' => static function (): void {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_media (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                rel_path VARCHAR(500) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime VARCHAR(100) NOT NULL,
                extension VARCHAR(12) NOT NULL,
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                checksum CHAR(64) NOT NULL,
                category VARCHAR(60) NOT NULL DEFAULT \'geral\',
                alt_text VARCHAR(255) NULL,
                uploaded_by BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_media_path (project_id, rel_path),
                INDEX idx_media_project (project_id, created_at),
                INDEX idx_media_category (project_id, category),
                INDEX idx_media_checksum (project_id, checksum),
                CONSTRAINT fk_media_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_media_references (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                media_id BIGINT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                page_id BIGINT UNSIGNED NULL,
                element_id BIGINT UNSIGNED NULL,
                ref_path VARCHAR(500) NOT NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_ref (media_id, element_id),
                INDEX idx_ref_media (media_id),
                INDEX idx_ref_page (page_id),
                CONSTRAINT fk_ref_media FOREIGN KEY (media_id)
                    REFERENCES cms_media (id) ON DELETE CASCADE,
                CONSTRAINT fk_ref_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },

    'down' => static function (): void {
        Db::exec('DROP TABLE IF EXISTS cms_media_references');
        Db::exec('DROP TABLE IF EXISTS cms_media');
    },
];
