<?php
declare(strict_types=1);

/** @var array $config */

function admin_use_database(): bool
{
    global $config;
    $env = getenv('CMS_USE_DATABASE');
    if ($env === '1' || strtolower((string) $env) === 'true') {
        return true;
    }
    return !empty($config['use_database']);
}

function admin_db_config(): array
{
    global $config;
    $db = is_array($config['db'] ?? null) ? $config['db'] : [];
    return [
        'host' => (string) (getenv('DB_HOST') ?: ($db['host'] ?? '127.0.0.1')),
        'port' => (int) (getenv('DB_PORT') ?: ($db['port'] ?? 3306)),
        'name' => (string) (getenv('DB_DATABASE') ?: ($db['name'] ?? 'fazendoemcasa')),
        'user' => (string) (getenv('DB_USERNAME') ?: ($db['user'] ?? 'fazendoemcasa')),
        'pass' => (string) (getenv('DB_PASSWORD') ?: ($db['pass'] ?? '')),
    ];
}

function admin_pdo(): ?PDO
{
    static $pdo = null;
    static $failed = false;
    if ($failed || !admin_use_database()) {
        return null;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = admin_db_config();
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'],
        $c['port'],
        $c['name']
    );
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException) {
        $failed = true;
        return null;
    }
    return $pdo;
}

function admin_db_connected(): bool
{
    return admin_pdo() instanceof PDO;
}

/** @return array<string, mixed>|null */
function cms_load_document_from_db(): ?array
{
    $pdo = admin_pdo();
    if (!$pdo) {
        return null;
    }
    $stmt = $pdo->query('SELECT document FROM cms_content WHERE id = 1 LIMIT 1');
    $row = $stmt ? $stmt->fetch() : false;
    if (!$row || !isset($row['document'])) {
        return [];
    }
    $doc = json_decode((string) $row['document'], true);
    return is_array($doc) ? $doc : [];
}

/** @param array<string, mixed> $document */
function cms_save_document_to_db(array $document, string $source = 'admin'): bool
{
    $pdo = admin_pdo();
    if (!$pdo) {
        return false;
    }
    $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE cms_content SET document = :doc WHERE id = 1');
        $stmt->execute(['doc' => $json]);
        if ($stmt->rowCount() === 0) {
            $ins = $pdo->prepare('INSERT INTO cms_content (id, document) VALUES (1, :doc)');
            $ins->execute(['doc' => $json]);
        }

        $rev = $pdo->prepare('INSERT INTO cms_revisions (document, source) VALUES (:doc, :src)');
        $rev->execute(['doc' => $json, 'src' => $source]);

        $pdo->commit();
        return true;
    } catch (Throwable) {
        $pdo->rollBack();
        return false;
    }
}

function cms_log_media(string $fieldId, string $path, int $bytes, string $mime = 'image/webp'): void
{
    $pdo = admin_pdo();
    if (!$pdo) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO cms_media (field_id, path, bytes, mime) VALUES (:fid, :path, :bytes, :mime)'
    );
    $stmt->execute([
        'fid' => $fieldId,
        'path' => $path,
        'bytes' => $bytes,
        'mime' => $mime,
    ]);
}
