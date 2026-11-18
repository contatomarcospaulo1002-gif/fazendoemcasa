<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

return [
    'up' => static function (): void {
        Db::exec(
            'CREATE TABLE IF NOT EXISTS cms_products (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                rel_path VARCHAR(255) NOT NULL,
                base_url VARCHAR(255) NOT NULL DEFAULT \'\',
                checkout_provider VARCHAR(40) NOT NULL DEFAULT \'hotmart\',
                checkout_url TEXT NOT NULL,
                meta_pixel_id VARCHAR(32) NOT NULL DEFAULT \'\',
                status ENUM(\'active\',\'disabled\') NOT NULL DEFAULT \'active\',
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_product_slug (slug),
                UNIQUE KEY uq_product_path (rel_path),
                INDEX idx_product_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $now = date('Y-m-d H:i:s');
        $products = [
            ['28 Dias em Casa', '28-dias-em-casa', '', 'https://fazendoemcasa.com/', 'hotmart', 'https://pay.hotmart.com/T108490682Y', '2111475296169479'],
            ['Kit Activités TEA', 'kit-activites-tea', 'kit-activites-tea', 'https://fazendoemcasa.com/kit-activites-tea/', 'hotmart', 'https://pay.hotmart.com/G108507678N', '4466538613660208'],
            ['Reliure Artisanale', 'reliure-artisanale', 'reliure-artisanale', 'https://fazendoemcasa.com/reliure-artisanale/', 'hotmart', 'https://pay.hotmart.com/T108507485P', '1617733936769759'],
        ];
        foreach ($products as $p) {
            Db::exec(
                'INSERT IGNORE INTO cms_products
                 (name, slug, rel_path, base_url, checkout_provider, checkout_url, meta_pixel_id, created_at, updated_at)
                 VALUES (:name, :slug, :path, :url, :provider, :checkout, :pixel, :created, :updated)',
                [
                    'name' => $p[0], 'slug' => $p[1], 'path' => $p[2], 'url' => $p[3],
                    'provider' => $p[4], 'checkout' => $p[5], 'pixel' => $p[6],
                    'created' => $now, 'updated' => $now,
                ]
            );
        }
    },

    'down' => static function (): void {
        Db::exec('DROP TABLE IF EXISTS cms_products');
    },
];
