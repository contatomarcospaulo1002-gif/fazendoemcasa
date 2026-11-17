<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * FASE 7 — Histórico, auditoria e restauração.
 *
 * `cms_element_versions` guarda o valor anterior de cada elemento
 * alterado em cada publicação — é o histórico que permite ver e
 * restaurar estados antigos.
 *
 * `cms_audit_logs` é o registo imutável de toda ação relevante.
 *
 * Nenhuma tabela existente é alterada.
 */
return [
    'up' => static function (): void {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_element_versions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                element_id BIGINT UNSIGNED NOT NULL,
                page_id BIGINT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                publication_id BIGINT UNSIGNED NULL,
                user_id BIGINT UNSIGNED NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_ver_element (element_id, created_at),
                INDEX idx_ver_page (page_id, created_at),
                CONSTRAINT fk_ver_element FOREIGN KEY (element_id)
                    REFERENCES cms_elements (id) ON DELETE CASCADE,
                CONSTRAINT fk_ver_page FOREIGN KEY (page_id)
                    REFERENCES cms_pages (id) ON DELETE CASCADE,
                CONSTRAINT fk_ver_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NULL,
                project_id INT UNSIGNED NULL,
                action VARCHAR(60) NOT NULL,
                target_type VARCHAR(30) NOT NULL,
                target_id BIGINT UNSIGNED NULL,
                detail_json TEXT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_audit_project (project_id, created_at),
                INDEX idx_audit_action (action, created_at),
                CONSTRAINT fk_audit_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },

    'down' => static function (): void {
        Db::exec('DROP TABLE IF EXISTS cms_audit_logs');
        Db::exec('DROP TABLE IF EXISTS cms_element_versions');
    },
];
