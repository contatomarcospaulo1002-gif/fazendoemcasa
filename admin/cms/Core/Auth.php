<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Sessão administrativa do CMS multi-projeto.
 *
 * Reaproveita a sessão e a tabela admin_users já existentes — não cria
 * um segundo sistema de autenticação.
 */
final class Auth
{
    private const SESSION_USER = 'admin_user_id';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    public static function userId(): ?int
    {
        self::startSession();
        $id = $_SESSION[self::SESSION_USER] ?? null;
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        $id = (int) $id;
        return $id > 0 ? $id : null;
    }

    public static function check(): bool
    {
        if (self::userId() !== null) {
            return true;
        }
        // Compatibilidade com o painel legado: quando não há contas em
        // admin_users, o admin antigo autentica por PIN e grava
        // $_SESSION['admin_ok']. O CMS novo respeita a mesma sessão para
        // não exigir dois logins.
        self::startSession();
        if (!empty($_SESSION['admin_ok'])) {
            return true;
        }
        return false;
    }

    /** @return array<string, mixed>|null */
    public static function user(): ?array
    {
        $id = self::userId();
        if ($id === null) {
            return null;
        }
        $row = Db::one('SELECT id, nome, login, email, ativo FROM admin_users WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($row === null || (int) ($row['ativo'] ?? 0) !== 1) {
            return null;
        }
        return $row;
    }

    public static function login(int $userId): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER] = $userId;
        unset($_SESSION['admin_ok']);
        Csrf::rotate();
    }

    public static function logout(): void
    {
        self::startSession();
        unset($_SESSION[self::SESSION_USER], $_SESSION['admin_ok'], $_SESSION['cms_csrf_token']);
        session_regenerate_id(true);
    }

    /**
     * Exige autenticação ou encerra com 401.
     */
    public static function require(): void
    {
        if (self::check()) {
            return;
        }
        Http::json(['ok' => false, 'error' => 'Não autenticado.', 'authenticated' => false], 401);
    }

    /**
     * Verifica se o usuário pode operar no projeto (owner/editor).
     * Sem tabela de permissões ainda, todo admin autenticado pode.
     * A assinatura já existe para não mudar chamadas na FASE 7.
     */
    public static function canEditProject(int $projectId): bool
    {
        if (!self::check()) {
            return false;
        }
        $userId = self::userId();
        // Modo PIN legado (sem contas): sessão única de administrador.
        if ($userId === null) {
            return true;
        }
        if (!Db::tableExists('cms_users_permissions')) {
            return true;
        }
        $row = Db::one(
            'SELECT role FROM cms_users_permissions
             WHERE user_id = :u AND (project_id = :p OR project_id IS NULL)
             ORDER BY project_id IS NULL ASC LIMIT 1',
            ['u' => $userId, 'p' => $projectId]
        );
        if ($row === null) {
            return false;
        }
        return in_array((string) $row['role'], ['owner', 'editor'], true);
    }
}
