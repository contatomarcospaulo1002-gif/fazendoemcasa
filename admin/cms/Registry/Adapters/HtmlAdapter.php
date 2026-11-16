<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry\Adapters;

require_once dirname(__DIR__, 2) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Registry\Adapter;
use FazendoEmCasa\Cms\Registry\Classifier;
use FazendoEmCasa\Cms\Registry\Element;

/**
 * Adaptador de HTML.
 *
 * Escrita determinística: o elemento é localizado por `data-cms-id`
 * (atributo único no documento) e o texto é substituído no nó. Como o
 * atributo é estável e único, o patch é cirúrgico e verificável.
 *
 * Limitação declarada: elementos sem `data-cms-id` não são editáveis
 * por este adaptador — caem para needs_adapter.
 */
final class HtmlAdapter implements Adapter
{
    public function __construct(private readonly Classifier $classifier = new Classifier())
    {
    }

    public function name(): string
    {
        return 'html';
    }

    public function supportsPageKind(string $kind): bool
    {
        return $kind === 'html';
    }

    public function capability(): array
    {
        return [
            'canWrite' => true,
            'writeStrategy' => 'DOMDocument + patch por data-cms-id',
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
            $type = $this->normalizeType((string) ($raw['type'] ?? 'text'));
            $value = isset($raw['preview']) ? (string) $raw['preview'] : null;

            $hasStable = $cmsId !== '';
            $hasValue = $value !== null && $value !== '';

            $classification = $this->classifier->classify($capability, $hasStable, $hasValue);

            $elements[] = new Element(
                selector: $hasStable ? '[data-cms-id="' . $cmsId . '"]' : (string) ($raw['tag'] ?? 'unknown'),
                type: $type,
                adapter: $this->name(),
                editability: $classification->level,
                editabilityReason: $classification->reason,
                cmsId: $hasStable ? $cmsId : null,
                value: $value,
                meta: [
                    'tag' => (string) ($raw['tag'] ?? ''),
                    'has_src' => (bool) ($raw['has_src'] ?? false),
                    'origin_path' => (string) ($analysis['origin_path'] ?? ''),
                ],
            );
        }

        $tagCount = [];
        foreach (($analysis['candidates'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $value = isset($raw['preview']) ? (string) $raw['preview'] : null;
            if ($value === null || $value === '') {
                continue;
            }

            $tag = (string) ($raw['tag'] ?? 'unknown');
            $tagCount[$tag] = ($tagCount[$tag] ?? 0) + 1;
            $positional = $tag . ':nth-of-type(' . $tagCount[$tag] . ')';

            // Candidato sem marcação: estrutura reconhecida, referência
            // não estável. Nunca pode ser promovido a editable.
            $classification = $this->classifier->classify($capability, false, true);

            $elements[] = new Element(
                selector: $positional,
                type: 'paragraph',
                adapter: $this->name(),
                editability: $classification->level,
                editabilityReason: $classification->reason,
                value: $value,
                meta: [
                    'tag' => $tag,
                    'candidate' => true,
                    'origin_path' => (string) ($analysis['origin_path'] ?? ''),
                ],
            );
        }

        return $elements;
    }

    private function normalizeType(string $raw): string
    {
        return match (strtolower($raw)) {
            'image' => 'image',
            'link' => 'link',
            'video' => 'video',
            'html' => 'html_component',
            default => 'paragraph',
        };
    }
}
