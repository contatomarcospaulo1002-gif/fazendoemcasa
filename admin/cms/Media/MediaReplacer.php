<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Media;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Core\Db;
use FazendoEmCasa\Cms\Editor\DraftService;

/**
 * Substituição de mídia com escopo explícito.
 *
 * Substituir uma imagem pode afetar várias páginas. Em vez de decidir
 * pelo utilizador, esta classe exige que o escopo seja declarado:
 *
 *   - `single`: apenas o elemento indicado;
 *   - `page`:   todos os usos na página indicada;
 *   - `all`:    todos os usos no projeto.
 *
 * A substituição NÃO escreve em disco. Ela cria/atualiza rascunhos, que
 * só viram ficheiro na FASE 6. Isso mantém a promessa da FASE 4: nada
 * toca o disco antes da publicação.
 */
final class MediaReplacer
{
    public function __construct(
        private readonly ReferenceIndex $index = new ReferenceIndex(),
        private readonly DraftService $drafts = new DraftService(),
    ) {
    }

    /**
     * @param string $scope single|page|all
     * @return array{ok: bool, error: ?string, affected: int, pages: list<int>}
     */
    public function replace(int $projectId, int $mediaId, string $newPath, string $scope, ?int $pageId = null, ?int $elementId = null): array
    {
        if (!in_array($scope, ['single', 'page', 'all'], true)) {
            return ['ok' => false, 'error' => 'Escopo inválido.', 'affected' => 0, 'pages' => []];
        }

        $media = Db::one(
            'SELECT id FROM cms_media WHERE id = :m AND project_id = :p LIMIT 1',
            ['m' => $mediaId, 'p' => $projectId]
        );
        if ($media === null) {
            return ['ok' => false, 'error' => 'Mídia não encontrada neste projeto.', 'affected' => 0, 'pages' => []];
        }

        $refs = $this->index->forMedia($mediaId);

        if ($scope === 'single') {
            if ($elementId === null) {
                return ['ok' => false, 'error' => 'elementId é obrigatório no escopo single.', 'affected' => 0, 'pages' => []];
            }
            $refs = array_values(array_filter($refs, static fn (array $r): bool => $r['element_id'] === $elementId));
        } elseif ($scope === 'page') {
            if ($pageId === null) {
                return ['ok' => false, 'error' => 'pageId é obrigatório no escopo page.', 'affected' => 0, 'pages' => []];
            }
            $refs = array_values(array_filter($refs, static fn (array $r): bool => $r['page_id'] === $pageId));
        }

        if ($refs === []) {
            return ['ok' => false, 'error' => 'Nenhuma referência no escopo indicado.', 'affected' => 0, 'pages' => []];
        }

        $byPage = [];
        foreach ($refs as $ref) {
            $byPage[$ref['page_id']][] = $ref['element_id'];
        }

        $affected = 0;
        foreach ($byPage as $pid => $elementIds) {
            $patch = [];
            foreach ($elementIds as $eid) {
                $patch[$eid] = $newPath;
            }

            $result = $this->drafts->save($projectId, (int) $pid, $patch);
            if ($result['ok']) {
                $affected += count($elementIds);
            }
        }

        return [
            'ok' => $affected > 0,
            'error' => $affected > 0 ? null : 'Nenhum rascunho pôde ser criado.',
            'affected' => $affected,
            'pages' => array_keys($byPage),
        ];
    }
}
