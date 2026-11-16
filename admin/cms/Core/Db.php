<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

use PDO;
use PDOException;

/**
 * Conexão PDO única, com prepared statements reais (sem emulação).
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static bool $failed = false;

    public static function pdo(): ?PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        if (self::$failed) {
            return null;
        }

        $config = Config::load();
        $db = $config->db();

        $host = (string) (getenv('DB_HOST') ?: ($db['host'] ?? '127.0.0.1'));
        $port = (int) (getenv('DB_PORT') ?: ($db['port'] ?? 3306));
        $name = (string) (getenv('DB_DATABASE') ?: ($db['name'] ?? ''));
        $user = (string) (getenv('DB_USERNAME') ?: ($db['user'] ?? ''));
        $pass = (string) (getenv('DB_PASSWORD') ?: ($db['pass'] ?? ''));

        if ($name === '') {
            self::$failed = true;
            return null;
        }

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException) {
            self::$failed = true;
            return null;
        }

        return self::$pdo;
    }

    public static function connected(): bool
    {
        return self::pdo() instanceof PDO;
    }

    /** @param array<string, mixed> $params */
    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $pdo = self::pdo();
        if (!$pdo instanceof PDO) {
            throw new \RuntimeException('Base de dados indisponível.');
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @param array<string, mixed> $params */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function all(string $sql, array $params = []): array
    {
        $rows = self::run($sql, $params)->fetchAll();
        return is_array($rows) ? $rows : [];
    }

    /** @param array<string, mixed> $params */
    public static function exec(string $sql, array $params = []): int
    {
        return self::run($sql, $params)->rowCount();
    }

    public static function lastId(): int
    {
        $pdo = self::pdo();
        return $pdo instanceof PDO ? (int) $pdo->lastInsertId() : 0;
    }

    public static function tableExists(string $table): bool
    {
        $pdo = self::pdo();
        if (!$pdo instanceof PDO) {
            return false;
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
            );
            $stmt->execute(['t' => $table]);
            return (bool) $stmt->fetchColumn();
        } catch (PDOException) {
            return false;
        }
    }
}
