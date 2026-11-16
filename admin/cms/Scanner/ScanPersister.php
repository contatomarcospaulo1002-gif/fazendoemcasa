<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Scanner;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * Persiste o resultado de uma varredura.
 *
 * Incremental: só regrava páginas cujo content_hash mudou. Remove do
 * inventário as que desapareceram do disco. Registra a varredura em
 * cms_scans para auditoria.
 */
final class ScanPersister
{
    /**
     * @param list<AnalysisResult> $results
     * @return array{found:int, changed:int, removed:int, unchanged:int, scan_id:int}
     */
    public function persist(int $projectId, array $results): array
    {
        $scanId = $this->startScan($projectId);

        $existing = [];
        foreach (Db::all(
            'SELECT id, rel_path, content_hash FROM cms_pages WHERE project_id = :p',
            ['p' => $projectId]
        ) as $row) {
            $existing[(string) $row['rel_path']] = $row;
        }

        $changed = 0;
        $unchanged = 0;
        $seen = [];

        foreach ($results as $result) {
            $seen[$result->relPath] = true;
            $current = $existing[$result->relPath] ?? null;

            if ($current !== null && (string) $current['content_hash'] === $result->contentHash
                && $result->contentHash !== '') {
                $unchanged++;
                Db::exec(
                    'UPDATE cms_pages SET last_scan_at = NOW(), status = :st, error_message = :err WHERE id = :id',
                    [
                        'st' => $result->ok() ? 'ok' : 'error',
                        'err' => $result->error,
                        'id' => (int) $current['id'],
                    ]
                );
                continue;
            }

            $this->upsert($projectId, $result);
            $changed++;
        }

        $removed = 0;
        foreach ($existing as $relPath => $row) {
            if (!isset($seen[$relPath])) {
                Db::exec('DELETE FROM cms_pages WHERE id = :id', ['id' => (int) $row['id']]);
                $removed++;
            }
        }

        $this->finishScan($scanId, count($results), $changed, $removed, null);
        Db::exec('UPDATE cms_projects SET last_scan_at = NOW() WHERE id = :p', ['p' => $projectId]);

        return [
            'found' => count($results),
            'changed' => $changed,
            'removed' => $removed,
            'unchanged' => $unchanged,
            'scan_id' => $scanId,
        ];
    }

    private function upsert(int $projectId, AnalysisResult $r): void
    {
        $isEntry = in_array(strtolower(basename($r->relPath)), ['index.html', 'index.htm', 'index.php'], true);

        Db::exec(
            'INSERT INTO cms_pages
                (project_id, rel_path, kind, title, content_hash, size_bytes,
                 element_count, image_count, link_count, is_entrypoint,
                 status, error_message, last_scan_at)
             VALUES
                (:p, :rel, :kind, :title, :hash, :size,
                 :els, :imgs, :links, :entry,
                 :st, :err, NOW())
             ON DUPLICATE KEY UPDATE
                kind = VALUES(kind),
                title = VALUES(title),
                content_hash = VALUES(content_hash),
                size_bytes = VALUES(size_bytes),
                element_count = VALUES(element_count),
                image_count = VALUES(image_count),
                link_count = VALUES(link_count),
                is_entrypoint = VALUES(is_entrypoint),
                status = VALUES(status),
                error_message = VALUES(error_message),
                last_scan_at = NOW()',
            [
                'p' => $projectId,
                'rel' => $r->relPath,
                'kind' => $r->kind,
                'title' => $r->title !== null ? mb_substr($r->title, 0, 255) : null,
                'hash' => $r->contentHash !== '' ? $r->contentHash : null,
                'size' => $r->sizeBytes,
                'els' => $r->elementCount(),
                'imgs' => $r->imageCount(),
                'links' => $r->linkCount(),
                'entry' => $isEntry ? 1 : 0,
                'st' => $r->ok() ? 'ok' : 'error',
                'err' => $r->error !== null ? mb_substr($r->error, 0, 500) : null,
            ]
        );
    }

    private function startScan(int $projectId): int
    {
        Db::exec(
            'INSERT INTO cms_scans (project_id, started_at, status) VALUES (:p, NOW(), \'running\')',
            ['p' => $projectId]
        );
        return Db::lastId();
    }

    private function finishScan(
        int $scanId,
        int $found,
        int $changed,
        int $removed,
        ?string $error
    ): void {
        Db::exec(
            'UPDATE cms_scans
             SET finished_at = NOW(), status = :st, pages_found = :f,
                 pages_changed = :c, pages_removed = :r, error_message = :e
             WHERE id = :id',
            [
                'st' => $error === null ? 'ok' : 'error',
                'f' => $found,
                'c' => $changed,
                'r' => $removed,
                'e' => $error,
                'id' => $scanId,
            ]
        );
    }
}
