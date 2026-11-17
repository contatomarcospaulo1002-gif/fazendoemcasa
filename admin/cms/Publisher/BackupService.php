<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Publisher;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * Snapshot do ficheiro ANTES da primeira escrita.
 *
 * Publicar sem backup é proibido. Se algo falhar depois da escrita, é
 * este conteúdo que volta ao disco.
 */
final class BackupService
{
    /**
     * Guarda o conteúdo atual do ficheiro. Devolve o id do backup, ou
     * null se o ficheiro ainda não existe (criação nova — nada a
     * restaurar).
     *
     * @return array{id: int|null, hash: string|null}
     */
    public function snapshot(int $projectId, ?int $pageId, string $absPath, string $relPath, string $reason = 'publish'): array
    {
        if (!is_file($absPath)) {
            return ['id' => null, 'hash' => null];
        }

        $content = (string) file_get_contents($absPath);
        $hash = hash('sha256', $content);

        Db::exec(
            'INSERT INTO cms_backups (project_id, page_id, rel_path, content, content_hash, size_bytes, reason, created_at)
             VALUES (:p, :pg, :rel, :content, :hash, :size, :reason, NOW())',
            [
                'p' => $projectId,
                'pg' => $pageId,
                'rel' => $relPath,
                'content' => $content,
                'hash' => $hash,
                'size' => strlen($content),
                'reason' => $reason,
            ]
        );

        return ['id' => (int) Db::lastId(), 'hash' => $hash];
    }

    /**
     * Restaura o backup mais recente do caminho. Devolve true se o
     * conteúdo voltou ao disco.
     */
    public function restore(int $backupId, string $absPath): bool
    {
        $backup = Db::one(
            'SELECT content FROM cms_backups WHERE id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $backupId]
        );
        if ($backup === null) {
            return false;
        }

        $content = (string) $backup['content'];
        $tmp = $absPath . '.cms-restore-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (!@rename($tmp, $absPath)) {
            @unlink($tmp);
            return false;
        }

        return hash_file('sha256', $absPath) === hash('sha256', $content);
    }
}
