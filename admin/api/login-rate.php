<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

const ADMIN_LOGIN_MAX_ATTEMPTS = 5;
const ADMIN_LOGIN_WINDOW_SECONDS = 900;

function admin_client_ip(): string
{
    $cf = (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
        return $cf;
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return '0.0.0.0';
    }
    return $ip;
}

function admin_login_rate_blocked(string $ip): bool
{
    if (admin_login_rate_ensure_table() && ($pdo = admin_pdo())) {
        $stmt = $pdo->prepare(
            'SELECT tentativas, ultimo_ts FROM login_tentativas WHERE ip = :ip LIMIT 1'
        );
        $stmt->execute(['ip' => $ip]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $attempts = (int) ($row['tentativas'] ?? 0);
        $last = strtotime((string) ($row['ultimo_ts'] ?? '')) ?: 0;
        if ($attempts >= ADMIN_LOGIN_MAX_ATTEMPTS && (time() - $last) < ADMIN_LOGIN_WINDOW_SECONDS) {
            return true;
        }
        if ((time() - $last) >= ADMIN_LOGIN_WINDOW_SECONDS) {
            admin_login_rate_clear($ip);
        }
        return false;
    }

    return admin_login_rate_blocked_file($ip);
}

function admin_login_rate_record_failure(string $ip): void
{
    if (admin_login_rate_ensure_table() && ($pdo = admin_pdo())) {
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO login_tentativas (ip, tentativas, primeiro_ts, ultimo_ts)
             VALUES (:ip, 1, :now, :now)
             ON DUPLICATE KEY UPDATE
               tentativas = IF(TIMESTAMPDIFF(SECOND, ultimo_ts, :now2) > :window, 1, tentativas + 1),
               primeiro_ts = IF(TIMESTAMPDIFF(SECOND, ultimo_ts, :now3) > :window2, :now4, primeiro_ts),
               ultimo_ts = :now5'
        );
        $stmt->execute([
            'ip' => $ip,
            'now' => $now,
            'now2' => $now,
            'now3' => $now,
            'now4' => $now,
            'now5' => $now,
            'window' => ADMIN_LOGIN_WINDOW_SECONDS,
            'window2' => ADMIN_LOGIN_WINDOW_SECONDS,
        ]);
        return;
    }

    admin_login_rate_record_failure_file($ip);
}

function admin_login_rate_clear(string $ip): void
{
    if (admin_login_rate_ensure_table() && ($pdo = admin_pdo())) {
        $stmt = $pdo->prepare('DELETE FROM login_tentativas WHERE ip = :ip');
        $stmt->execute(['ip' => $ip]);
        return;
    }
    admin_login_rate_clear_file($ip);
}

function admin_login_rate_ensure_table(): bool
{
    if (!admin_use_database() || !admin_pdo()) {
        return false;
    }
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $pdo = admin_pdo();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS login_tentativas (
              ip VARCHAR(45) NOT NULL PRIMARY KEY,
              tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
              primeiro_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              ultimo_ts TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              INDEX idx_login_ultimo (ultimo_ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $ok = true;
    } catch (Throwable) {
        $ok = false;
    }
    return $ok;
}

function admin_login_rate_file_path(): string
{
    $dir = dirname(__DIR__) . '/var';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir . '/login_rate.json';
}

/** @return array<string, array{tentativas: int, primeiro: int, ultimo: int}> */
function admin_login_rate_load_file(): array
{
    $path = admin_login_rate_file_path();
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function admin_login_rate_save_file(array $data): void
{
    $path = admin_login_rate_file_path();
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    @chmod($path, 0640);
}

function admin_login_rate_blocked_file(string $ip): bool
{
    $data = admin_login_rate_load_file();
    $row = $data[$ip] ?? null;
    if (!$row) {
        return false;
    }
    $now = time();
    if (($now - (int) ($row['ultimo'] ?? 0)) >= ADMIN_LOGIN_WINDOW_SECONDS) {
        unset($data[$ip]);
        admin_login_rate_save_file($data);
        return false;
    }
    return (int) ($row['tentativas'] ?? 0) >= ADMIN_LOGIN_MAX_ATTEMPTS;
}

function admin_login_rate_record_failure_file(string $ip): void
{
    $data = admin_login_rate_load_file();
    $now = time();
    $row = $data[$ip] ?? ['tentativas' => 0, 'primeiro' => $now, 'ultimo' => $now];
    if (($now - (int) ($row['ultimo'] ?? 0)) >= ADMIN_LOGIN_WINDOW_SECONDS) {
        $row = ['tentativas' => 0, 'primeiro' => $now, 'ultimo' => $now];
    }
    $row['tentativas'] = (int) $row['tentativas'] + 1;
    $row['ultimo'] = $now;
    $data[$ip] = $row;
    admin_login_rate_save_file($data);
}

function admin_login_rate_clear_file(string $ip): void
{
    $data = admin_login_rate_load_file();
    unset($data[$ip]);
    admin_login_rate_save_file($data);
}
