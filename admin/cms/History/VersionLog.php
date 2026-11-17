<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\History;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * Histórico de valores por elemento.
 *
 * Grava o valor ANTES e DEPOIS de cada publicação. É o que permite à
 * UI mostrar o que mudou e restaurar um estado anterior.
 */
final class VersionLog
{
    /**
     * Registra a versão de cada elemento alterado numa publicação.
     *
     * @param array<int, string> $patch element_id => novo valor
     * @param array<int, array<string, mixed>> $elements elementos indexados por id
     */
    public static function recordPublication(
        int $publicationId,
        int $projectId,
        int $pageId,
        ?int $userId,
        array $patch,
        array $elements,
    ): void {
        foreach ($patch as $elementId => $newValue) {
            $element = $elements[$elementId] ?? null;
            if ($element === null) {
                continue;
            }

            Db::exec(
                'INSERT INTO cms_element_versions
                    (element_id, page_id, project_id, publication_id, user_id,
                     old_value, new_value, created_at)
                 VALUES (:e, :pg, :p, :pub, :u, :old, :new, NOW())',
                [
                    'e' => $elementId,
                    'pg' => $pageId,
                    'p' => $projectId,
                    'pub' => $publicationId,
                    'u' => $userId,
                    'old' => (string) ($element['value_current'] ?? ''),
                    'new' => (string) $newValue,
                ]
            );
        }
    }

    /**
     * Histórico de um elemento, mais recente primeiro.
     *
     * @return list<array<string, mixed>>
     */
    public static function forElement(int $elementId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        return Db::all(
            'SELECT v.*, pub.status AS publication_status
             FROM cms_element_versions v
             LEFT JOIN cms_publications pub ON pub.id = v.publication_id
             WHERE v.element_id = :e
             ORDER BY v.id DESC LIMIT ' . $limit,
            ['e' => $elementId]
        );
    }

    /**
     * Histórico completo de uma página (para a UI de restauração).
     *
     * @return list<array<string, mixed>>
     */
    public static function forPage(int $pageId, int $limit = 100): array
    {
        $limit = max(1, min(300, $limit));
        return Db::all(
            'SELECT v.*, el.selector, el.type
             FROM cms_element_versions v
             LEFT JOIN cms_elements el ON el.id = v.element_id
             WHERE v.page_id = :pg
             ORDER BY v.id DESC LIMIT ' . $limit,
            ['pg' => $pageId]
        );
    }
}
