<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Calcula o nível de editabilidade de um elemento.
 *
 * Fontes de evidência, em ordem de prioridade:
 *   1. O adaptador declara que sabe escrever? Se não → read_only.
 *   2. O elemento tem referência estável (data-cms-id ou selector único)?
 *      Se não → needs_adapter (não há como reencontrá-lo com segurança).
 *   3. O elemento tem valor atual legível? Se não → unconfigured.
 *   4. Caso contrário → editable.
 *
 * O Classifier NUNCA promove algo a `editable` sem as três provas.
 */
final class Classifier
{
    /**
     * @param array{canWrite: bool, writeStrategy: string, limitation: ?string} $capability
     * @param bool $hasStableSelector
     * @param bool $hasReadableValue
     */
    public function classify(
        array $capability,
        bool $hasStableSelector,
        bool $hasReadableValue
    ): Classification {
        // 1. O adaptador não sabe escrever: nada é editável.
        if (!$capability['canWrite']) {
            return new Classification(
                Editability::READ_ONLY,
                'Adaptador "' . $capability['writeStrategy'] . '" não suporta escrita.'
                    . ($capability['limitation'] !== null ? ' ' . $capability['limitation'] : '')
            );
        }

        // 2. Sem referência estável não há como reencontrar o elemento
        //    depois de uma alteração no ficheiro.
        if (!$hasStableSelector) {
            return new Classification(
                Editability::NEEDS_ADAPTER,
                'Sem referência estável (data-cms-id ausente); exige adaptador dedicado.'
            );
        }

        // 3. Valor ilegível: o scanner viu a estrutura mas não o conteúdo.
        if (!$hasReadableValue) {
            return new Classification(
                Editability::UNCONFIGURED,
                'Conteúdo não extraído pelo analisador; requer configuração.'
            );
        }

        // 4. Todas as provas passaram.
        return new Classification(
            Editability::EDITABLE,
            'Adaptador ' . $capability['writeStrategy'] . ' com referência estável e valor legível.'
        );
    }
}
