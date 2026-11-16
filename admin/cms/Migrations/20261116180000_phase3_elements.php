<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * FASE 3 — Content Registry.
 *
 * Cria o registro de elementos editáveis. Nenhuma tabela existente é
 * alterada. O nível de editabilidade é sempre calculado pelo Classifier,
 * nunca presumido.
 */
return [
    'up' => static function (): void {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_elements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                page_id BIGINT UNSIGNED NOT NULL,
                section VARCHAR(120) NULL,
                cms_id VARCHAR(160) NULL,
                type ENUM(
                    \'image\',\'logo\',\'banner\',\'background\',\'title\',
                    \'subtitle\',\'paragraph\',\'button\',\'link\',\'video\',
                    \'price\',\'product\',\'icon\',\'html_component\',\'metadata\'
                ) NOT NULL DEFAULT \'paragraph\',
                name VARCHAR(200) NULL,
                selector VARCHAR(400) NOT NULL,
                origin_path VARCHAR(255) NOT NULL,
                origin_line INT UNSIGNED NULL,
                adapter VARCHAR(40) NOT NULL,
                editability ENUM(
                    \'editable\',\'needs_adapter\',\'read_only\',\'unconfigured\'
                ) NOT NULL DEFAULT \'unconfigured\',
                editability_reason VARCHAR(255) NULL,
                value_current MEDIUMTEXT NULL,
                discovered_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_element (page_id, selector, type),
                INDEX idx_element_project (project_id),
                INDEX idx_element_page (page_id),
                INDEX idx_element_editability (project_id, editability),
                CONSTRAINT fk_element_project FOREIGN KEY (project_id)
                    REFERENCES cms_projects (id) ON DELETE CASCADE,
                CONSTRAINT fk_element_page FOREIGN KEY (page_id)
                    REFERENCES cms_pages (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    },

    'down' => static function (): void {
        Db::exec('DROP TABLE IF EXISTS cms_elements');
    },
];
