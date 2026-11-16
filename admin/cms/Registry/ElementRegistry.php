<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;

/**
 * Persiste elementos em cms_elements.
 *
 * Chave natural: (page_id, selector, type). Um elemento que muda de
 * nível entre varreduras é atualizado, não duplicado. Elementos que
 * desapareceram do ficheiro são removidos apenas quando a varredura é
 * completa (o chamador controla isso com `prune`).
 */
final class ElementRegistry
{
    /**
     * @param list<Element> $elements
     * @return array{inserted:int, updated:int, pruned:int, total:int}
     */
    public function upsertPage(
        int $projectId,
        int $pageId,
        array $elements,
        bool $prune = true,
        string $originPath = ''
    ): array {
        $existing = [];
        foreach (Db::all(
            'SELECT id, selector, type, editability, value_current FROM cms_elements WHERE page_id = :p',
            ['p' => $pageId]
        ) as $row) {
            $existing[$this->key((string) $row['selector'], (string) $row['type'])] = $row;
        }

        $inserted = 0;
        $updated = 0;
        $seen = [];

        foreach ($elements as $element) {
            $key = $this->key($element->selector, $element->type);
            $seen[$key] = true;
            $current = $existing[$key] ?? null;

            if ($current === null) {
                $this->insert($projectId, $pageId, $element, $originPath);
                $inserted++;
                continue;
            }

            // Só regrava se algo relevante mudou.
            $changed = (string) $current['editability'] !== $element->editability
                || $this->stringify($current['value_current']) !== $this->stringify($element->value);

            if ($changed) {
                $this->update((int) $current['id'], $element);
                $updated++;
            }
        }

        $pruned = 0;
        if ($prune) {
            foreach ($existing as $key => $row) {
                if (!isset($seen[$key])) {
                    Db::exec('DELETE FROM cms_elements WHERE id = :id', ['id' => (int) $row['id']]);
                    $pruned++;
                }
            }
        }

        return [
            'inserted' => $inserted,
            'updated' => $updated,
            'pruned' => $pruned,
            'total' => count($elements),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forPage(int $pageId, ?string $editability = null): array
    {
        $sql = 'SELECT * FROM cms_elements WHERE page_id = :p';
        $params = ['p' => $pageId];
        if ($editability !== null && $editability !== '') {
            $sql .= ' AND editability = :e';
            $params['e'] = $editability;
        }
        $sql .= ' ORDER BY id ASC';
        return Db::all($sql, $params);
    }

    /**
     * Contagem por nível, para os cards da Central.
     *
     * @return array<string, int>
     */
    public function countsByLevel(int $projectId): array
    {
        $out = array_fill_keys(Editability::ALL, 0);
        foreach (Db::all(
            'SELECT editability, COUNT(*) AS n FROM cms_elements
             WHERE project_id = :p GROUP BY editability',
            ['p' => $projectId]
        ) as $row) {
            $level = (string) $row['editability'];
            if (isset($out[$level])) {
                $out[$level] = (int) $row['n'];
            }
        }
        return $out;
    }

    public function deleteForPage(int $pageId): void
    {
        Db::exec('DELETE FROM cms_elements WHERE page_id = :p', ['p' => $pageId]);
    }

    private function key(string $selector, string $type): string
    {
        return $type . "\0" . $selector;
    }

    private function stringify(mixed $value): string
    {
        return $value === null ? "\0null" : (string) $value;
    }

    private function insert(int $projectId, int $pageId, Element $e, string $originPath = ''): void
    {
        Db::exec(
            'INSERT INTO cms_elements
                (project_id, page_id, section, cms_id, type, name, selector,
                 origin_path, origin_line, adapter, editability, editability_reason,
                 value_current, discovered_at, updated_at)
             VALUES
                (:pid, :page, :section, :cms_id, :type, :name, :selector,
                 :origin, :line, :adapter, :edit, :reason,
                 :value, NOW(), NOW())',
            [
                'pid' => $projectId,
                'page' => $pageId,
                'section' => $e->section,
                'cms_id' => $e->cmsId,
                'type' => $e->type,
                'name' => $e->name,
                'selector' => $e->selector,
                'origin' => (string) ($e->meta['origin_path'] ?? $originPath),
                'line' => $e->originLine,
                'adapter' => $e->adapter,
                'edit' => $e->editability,
                'reason' => mb_substr($e->editabilityReason, 0, 255),
                'value' => $e->value,
            ]
        );
    }

    private function update(int $id, Element $e): void
    {
        Db::exec(
            'UPDATE cms_elements
             SET cms_id = :cms_id, section = :section, name = :name,
                 adapter = :adapter, editability = :edit, editability_reason = :reason,
                 value_current = :value, updated_at = NOW()
             WHERE id = :id',
            [
                'cms_id' => $e->cmsId,
                'section' => $e->section,
                'name' => $e->name,
                'adapter' => $e->adapter,
                'edit' => $e->editability,
                'reason' => mb_substr($e->editabilityReason, 0, 255),
                'value' => $e->value,
                'id' => $id,
            ]
        );
    }
}
