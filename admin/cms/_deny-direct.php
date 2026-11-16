<?php
declare(strict_types=1);

/**
 * Guarda de execução direta.
 *
 * Qualquer arquivo interno de admin/cms/ (Core, Projects, Migrations,
 * views) inclui esta guarda no topo. Se for executado diretamente pela
 * web — sem passar pelo bootstrap — responde 404.
 *
 * Defesa em profundidade: protege mesmo se o .htaccess for removido e o
 * Apache/configuração servir o arquivo.
 */

if (!defined('CMS_ROOT')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}
