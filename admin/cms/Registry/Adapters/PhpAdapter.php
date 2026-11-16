<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry\Adapters;

require_once dirname(__DIR__, 2) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Registry\Adapter;
use FazendoEmCasa\Cms\Registry\Classifier;
use FazendoEmCasa\Cms\Registry\Element;

/**
 * Adaptador de PHP.
 *
 * NÃO sabe escrever. Substituir um literal de string em PHP por offset
 * é frágil: o mesmo literal pode aparecer em contexto de código, e uma
 * alteração pode quebrar a sintaxe. Enquanto não houver um tokenizer
 * com mapeamento de posição confiável (FASE 6), este adaptador declara
 * honestamente `canWrite: false`.
 *
 * Consequência: todo elemento PHP cai para `read_only` com motivo
 * explícito. É melhor bloquear do que corromper.
 */
final class PhpAdapter implements Adapter
{
    public function __construct(private readonly Classifier $classifier = new Classifier())
    {
    }

    public function name(): string
    {
        return 'php';
    }

    public function supportsPageKind(string $kind): bool
    {
        return $kind === 'php';
    }

    public function capability(): array
    {
        return [
            'canWrite' => false,
            'writeStrategy' => 'tokenizer sem mapeamento de posição',
            'limitation' => 'Escrita em PHP exige tokenizer com offsets confiáveis (FASE 6).',
        ];
    }

    /** @return list<Element> */
    public function extract(array $analysis): array
    {
        $elements = [];
        $capability = $this->capability();

        foreach (($analysis['elements'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $cmsId = isset($raw['cms_id']) ? trim((string) $raw['cms_id']) : '';
            $value = isset($raw['preview']) ? (string) $raw['preview'] : null;

            $classification = $this->classifier->classify(
                $capability,
                $cmsId !== '',
                $value !== null && $value !== ''
            );

            $elements[] = new Element(
                selector: $cmsId !== '' ? '[data-cms-id="' . $cmsId . '"]' : (string) ($raw['cms_id'] ?? 'php'),
                type: 'paragraph',
                adapter: $this->name(),
                editability: $classification->level,
                editabilityReason: $classification->reason,
                cmsId: $cmsId !== '' ? $cmsId : null,
                value: $value,
                meta: ['tag' => 'php'],
            );
        }

        return $elements;
    }
}
