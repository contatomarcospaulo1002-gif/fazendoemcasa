<?php
declare(strict_types=1);

/**
 * Bootstrap do CMS multi-projeto.
 *
 * Ponto único de inicialização para todo o código em admin/cms/.
 * Não é acessível pela web (bloqueado no .htaccess da raiz).
 */

if (defined('CMS_BOOTSTRAPPED')) {
    return;
}
define('CMS_BOOTSTRAPPED', true);

define('CMS_ROOT', __DIR__);
define('CMS_ADMIN_ROOT', dirname(__DIR__));
define('CMS_PROJECT_ROOT', dirname(CMS_ADMIN_ROOT));

spl_autoload_register(static function (string $class): void {
    $prefix = 'FazendoEmCasa\\Cms\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = CMS_ROOT . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require_once CMS_ROOT . '/Core/Config.php';
require_once CMS_ROOT . '/Core/Db.php';
require_once CMS_ROOT . '/Core/Json.php';
require_once CMS_ROOT . '/Core/PathException.php';
require_once CMS_ROOT . '/Core/PathGuard.php';
require_once CMS_ROOT . '/Core/Csrf.php';
require_once CMS_ROOT . '/Core/Auth.php';
require_once CMS_ROOT . '/Core/Http.php';
require_once CMS_ROOT . '/Core/Migrator.php';
