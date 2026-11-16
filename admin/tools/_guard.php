<?php
declare(strict_types=1);

/**
 * Guard de CLI para scripts de manutenção em admin/tools/.
 *
 * Estes scripts alteram conteúdo, schema e arquivos. Eles NUNCA devem ser
 * executáveis por HTTP. Este guard é a segunda camada de defesa: mesmo que
 * o .htaccess da pasta seja ignorado (Nginx, AllowOverride None, etc.),
 * a requisição web morre aqui.
 *
 * Primeira camada: admin/tools/.htaccess (Require all denied).
 *
 * Incidente que motivou este arquivo (2026-11-16): um GET em
 * /admin/tools/extract-content.php retornou 200 e regravou
 * admin/content.json em produção.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}
