<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * FASE 1 — Central de Projetos.
 *
 * Cria apenas tabelas novas. Não toca em cms_content, cms_revisions,
 * cms_media, admin_users, member_users ou login_tentativas.
 */
return [
    'up' => static function (): void {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_projects (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(80) NOT NULL,
                name VARCHAR(160) NOT NULL,
                description TEXT NULL,
                rel_path VARCHAR(255) NOT NULL,
                base_url VARCHAR(255) NOT NULL DEFAULT \'\',
                logo_media_id BIGINT UNSIGNED NULL,
                status ENUM(\'active\',\'disabled\') NOT NULL DEFAULT \'active\',
                discovery ENUM(\'manual\',\'discovered\') NOT NULL DEFAULT \'manual\',
                last_scan_at DATETIME NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_project_slug (slug),
                UNIQUE KEY uq_project_path (rel_path),
                INDEX idx_project_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_project_settings (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                setting_key VARCHAR(120) NOT NULL,
                setting_value MEDIUMTEXT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_setting (project_id, setting_key),
                CONSTRAINT fk_settings_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_users_permissions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NULL,
                role ENUM(\'owner\',\'editor\',\'viewer\') NOT NULL DEFAULT \'editor\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_perm (user_id, project_id),
                INDEX idx_perm_project (project_id),
                CONSTRAINT fk_perm_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },

    'down' => static function (): void {
        Db::exec('DROP TABLE IF EXISTS cms_users_permissions');
        Db::exec('DROP TABLE IF EXISTS cms_project_settings');
        Db::exec('DROP TABLE IF EXISTS cms_projects');
    },
];
