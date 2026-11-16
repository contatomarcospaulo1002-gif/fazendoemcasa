<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry\Adapters;

require_once dirname(__DIR__, 2) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Registry\Adapter;
use FazendoEmCasa\Cms\Registry\Classifier;
use FazendoEmCasa\Cms\Registry\Element;

/**
 * Adaptador de componente — independe da tecnologia do ficheiro.
 *
 * Trabalha exclusivamente sobre atributos `data-cms-*`. É a ponte entre
 * o editor visual (FASE 4) e o ficheiro real: o editor só conhece
 * `data-cms-id`, e este adaptador encontra o elemento em HTML ou PHP
 * com a mesma semântica.
 *
 * Só aceita elementos que tenham as quatro marcas: id, type, e um
 * contentor pai identificável. Sem isso, não há como provar a escrita.
 */
final class ComponentAdapter implements Adapter
{
    /** Níveis de contrato aceitos em data-cms-type. */
    private const KNOWN_TYPES = [
        'text', 'image', 'link', 'html', 'video', 'button', 'price', 'metadata',
    ];

    public function __construct(private readonly Classifier $classifier = new Classifier())
    {
    }

    public function name(): string
    {
        return 'component';
    }

    public function supportsPageKind(string $kind): bool
    {
        return in_array($kind, ['html', 'php'], true);
    }

    public function capability(): array
    {
        return [
            'canWrite' => true,
            'writeStrategy' => 'resolução por data-cms-id + data-cms-type',
            'limitation' => null,
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
            if ($cmsId === '') {
                // Sem data-cms-id este adaptador não tem contrato.
                continue;
            }

            $declared = strtolower(trim((string) ($raw['type'] ?? 'text')));
            $componentType = in_array($declared, self::KNOWN_TYPES, true) ? $declared : 'text';
            $value = isset($raw['preview']) ? (string) $raw['preview'] : null;

            $classification = $this->classifier->classify(
                $capability,
                true,
                $value !== null && $value !== ''
            );

            $elements[] = new Element(
                selector: '[data-cms-id="' . $cmsId . '"]',
                type: $this->mapToElementType($componentType),
                adapter: $this->name(),
                editability: $classification->level,
                editabilityReason: $classification->reason,
                cmsId: $cmsId,
                value: $value,
                meta: [
                    'component_type' => $componentType,
                    'tag' => (string) ($raw['tag'] ?? ''),
                ],
            );
        }

        return $elements;
    }

    private function mapToElementType(string $componentType): string
    {
        return match ($componentType) {
            'image' => 'image',
            'link' => 'link',
            'video' => 'video',
            'html' => 'html_component',
            'button' => 'button',
            'price' => 'price',
            'metadata' => 'metadata',
            default => 'paragraph',
        };
    }
}
