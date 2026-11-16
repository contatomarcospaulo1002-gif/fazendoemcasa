<?php
declare(strict_types=1);

/**
 * Entrypoint público da UI do CMS multi-projeto.
 *
 * Toda a lógica e views vivem em admin/cms/ (bloqueado na web).
 * Este arquivo apenas autentica e inclui a view.
 */

require __DIR__ . '/cms/bootstrap.php';

use FazendoEmCasa\Cms\Core\Auth;
use FazendoEmCasa\Cms\Core\Db;

Auth::startSession();

if (!Auth::check()) {
    // Sem sessão: manda para o login já existente.
    header('Location: /admin/', true, 302);
    exit;
}

if (!Db::connected() || !Db::tableExists('cms_projects')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="pt-PT"><head><meta charset="utf-8">';
    echo '<title>Central CMS — configuração pendente</title>';
    echo '<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:80px auto;padding:0 24px;color:#1a1a1a}code{background:#f3f3f3;padding:2px 6px;border-radius:4px}</style>';
    echo '</head><body>';
    echo '<h1>Configuração pendente</h1>';
    if (!Db::connected()) {
        echo '<p>A base de dados não está acessível. Verifica <code>admin/config.local.php</code>.</p>';
    } else {
        echo '<p>A Central de Projetos ainda não foi inicializada.</p>';
        echo '<p>Corre no servidor:</p><pre><code>php admin/cms/bin/migrate.php</code></pre>';
    }
    echo '</body></html>';
    exit;
}

$view = __DIR__ . '/cms/views/central.php';
require $view;
