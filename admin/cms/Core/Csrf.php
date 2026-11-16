<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Proteção CSRF.
 *
 * Token por sessão, rotacionado no login/logout, exigido em todo
 * POST/PUT/DELETE. Comparação com hash_equals (tempo constante).
 */
final class Csrf
{
    private const SESSION_KEY = 'cms_csrf_token';
    private const HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Sessão não iniciada.');
        }
        $existing = $_SESSION[self::SESSION_KEY] ?? null;
        if (is_string($existing) && strlen($existing) === 64) {
            return $existing;
        }
        $token = bin2hex(random_bytes(32));
        $_SESSION[self::SESSION_KEY] = $token;
        return $token;
    }

    public static function rotate(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new \RuntimeException('Sessão não iniciada.');
        }
        unset($_SESSION[self::SESSION_KEY]);
        return self::token();
    }

    /**
     * Valida o token do request. Retorna false se ausente ou inválido.
     */
    public static function validateRequest(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }
        $expected = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $provided = $_SERVER[self::HEADER] ?? '';
        if (!is_string($provided) || $provided === '') {
            // Fallback: campo no corpo (formulários clássicos).
            $provided = isset($_POST['_csrf']) && is_string($_POST['_csrf'])
                ? $_POST['_csrf']
                : '';
        }
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }

    /**
     * Exige token válido ou encerra com 403.
     */
    public static function requireValid(): void
    {
        if (self::validateRequest()) {
            return;
        }
        Http::json(['ok' => false, 'error' => 'Token CSRF inválido ou ausente.'], 403);
    }

    /**
     * Métodos que exigem CSRF.
     */
    public static function methodRequiresToken(string $method): bool
    {
        return in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }
}
