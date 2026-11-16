<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Respostas HTTP JSON padronizadas.
 */
final class Http
{
    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo Json::encode($payload);
        exit;
    }

    /** @param array<string, mixed> $payload */
    public static function ok(array $payload = []): never
    {
        self::json(['ok' => true] + $payload);
    }

    public static function error(string $message, int $code = 400, array $extra = []): never
    {
        self::json(['ok' => false, 'error' => $message] + $extra, $code);
    }

    /** @return array<string, mixed> */
    public static function jsonBody(): array
    {
        $raw = (string) file_get_contents('php://input');
        $decoded = Json::decodeObject($raw);
        if ($decoded === null) {
            self::error('Corpo JSON inválido.', 400);
        }
        return $decoded;
    }

    public static function requireMethod(string ...$allowed): string
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, array_map('strtoupper', $allowed), true)) {
            self::error('Método inválido.', 405);
        }
        return $method;
    }

    public static function clientIp(): string
    {
        $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}
