<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Media;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * Índice de onde cada mídia é usada.
 *
 * Substituir uma imagem é uma operação perigosa: pode afetar várias
 * páginas sem que o utilizador saiba. Este índice existe para que a UI
 * mostre o alcance REAL antes de confirmar — e para que a substituição
 * possa ser feita com escopo explícito.
 */
final class ReferenceIndex
{
    /**
     * Reconstrói o índice do projeto a partir dos elementos registados.
     *
     * Uma referência é registada quando o valor de um elemento de mídia
     * (image, logo, banner, background, icon, video) corresponde ao
     * caminho de uma mídia da biblioteca.
     *
     * @return int número de referências registadas
     */
    public function rebuild(int $projectId): int
    {
        Db::exec('DELETE FROM cms_media_references WHERE project_id = :p', ['p' => $projectId]);

        $media = [];
        foreach (Db::all(
            'SELECT id, rel_path FROM cms_media WHERE project_id = :p',
            ['p' => $projectId]
        ) as $row) {
            $media[(string) $row['rel_path']] = (int) $row['id'];
        }

        if ($media === []) {
            return 0;
        }

        $count = 0;
        foreach (Db::all(
            "SELECT id, page_id, value_current, type FROM cms_elements
             WHERE page_id IN (SELECT id FROM cms_pages WHERE project_id = :p)
               AND type IN ('image','logo','banner','background','icon','video')",
            ['p' => $projectId]
        ) as $el) {
            $value = $this->normalize((string) ($el['value_current'] ?? ''));
            if ($value === '') {
                continue;
            }

            $mediaId = $this->match($value, $media);
            if ($mediaId === null) {
                continue;
            }

            Db::exec(
                'INSERT IGNORE INTO cms_media_references
                    (media_id, project_id, page_id, element_id, ref_path, created_at)
                 VALUES (:m, :p, :pg, :e, :ref, NOW())',
                [
                    'm' => $mediaId,
                    'p' => $projectId,
                    'pg' => (int) $el['page_id'],
                    'e' => (int) $el['id'],
                    'ref' => $value,
                ]
            );
            $count++;
        }

        return $count;
    }

    /**
     * Onde uma mídia é usada, com dados legíveis para a UI.
     *
     * @return list<array{page_id:int, page_path:string, element_id:int, ref_path:string}>
     */
    public function forMedia(int $mediaId): array
    {
        $rows = Db::all(
            'SELECT r.page_id, r.element_id, r.ref_path, p.rel_path AS page_path
             FROM cms_media_references r
             LEFT JOIN cms_pages p ON p.id = r.page_id
             WHERE r.media_id = :m
             ORDER BY p.rel_path',
            ['m' => $mediaId]
        );

        return array_map(static fn (array $r): array => [
            'page_id' => (int) $r['page_id'],
            'page_path' => (string) ($r['page_path'] ?? '—'),
            'element_id' => (int) $r['element_id'],
            'ref_path' => (string) $r['ref_path'],
        ], $rows);
    }

    /** @return array<int, int> media_id => número de usos */
    public function usageCounts(int $projectId): array
    {
        $out = [];
        foreach (Db::all(
            'SELECT media_id, COUNT(*) AS n FROM cms_media_references
             WHERE project_id = :p GROUP BY media_id',
            ['p' => $projectId]
        ) as $row) {
            $out[(int) $row['media_id']] = (int) $row['n'];
        }
        return $out;
    }

    /** Mídias da biblioteca que não são usadas em nenhuma página. */
    public function unused(int $projectId): array
    {
        return Db::all(
            'SELECT m.* FROM cms_media m
             LEFT JOIN cms_media_references r ON r.media_id = m.id
             WHERE m.project_id = :p AND r.id IS NULL
             ORDER BY m.created_at DESC',
            ['p' => $projectId]
        );
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = (string) preg_replace('#^https?://[^/]+/#i', '', $value);
        return ltrim(str_replace('\\', '/', $value), '/');
    }

    /**
     * @param array<string, int> $media
     */
    private function match(string $value, array $media): ?int
    {
        if (isset($media[$value])) {
            return $media[$value];
        }

        // O elemento pode referenciar apenas o nome do ficheiro, ou um
        // caminho relativo à página. Compara pelo sufixo do caminho.
        foreach ($media as $relPath => $id) {
            if ($relPath !== '' && (str_ends_with($value, $relPath) || str_ends_with($relPath, $value))) {
                return $id;
            }
        }
        return null;
    }
}
