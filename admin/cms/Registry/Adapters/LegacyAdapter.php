<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry\Adapters;

require_once dirname(__DIR__, 2) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Registry\Adapter;
use FazendoEmCasa\Cms\Registry\Classifier;
use FazendoEmCasa\Cms\Registry\Element;

/**
 * Ponte para o editor legado (`admin/site-map.json`).
 *
 * O painel antigo já tem 17 grupos mapeados com selectors CSS reais e
 * validados em produção. Reescrever esse mapa seria arriscado. Este
 * adaptador importa os campos existentes para o Registry, para que a
 * Central veja o mesmo conteúdo que o painel legado vê.
 *
 * Escrita: delegada ao fluxo legado (`save-content.php` + `apply-index.php`),
 * que já tem backup e verificação própria. Por isso declara canWrite
 * somente quando o campo tem selector e tipo conhecidos.
 */
final class LegacyAdapter implements Adapter
{
    private const KNOWN_FIELD_TYPES = ['text', 'html', 'attr', 'image', 'image-slot'];

    public function __construct(private readonly Classifier $classifier = new Classifier())
    {
    }

    public function name(): string
    {
        return 'legacy';
    }

    public function supportsPageKind(string $kind): bool
    {
        // O mapa legado descreve a página inicial (index.html).
        return $kind === 'html';
    }

    public function capability(): array
    {
        return [
            'canWrite' => true,
            'writeStrategy' => 'delegação ao fluxo legado (save-content + apply-index)',
            'limitation' => null,
        ];
    }

    /**
     * @param array<string, mixed> $analysis deve conter 'legacy_fields'
     * @return list<Element>
     */
    public function extract(array $analysis): array
    {
        $elements = [];
        $capability = $this->capability();
        $fields = $analysis['legacy_fields'] ?? null;

        if (!is_array($fields)) {
            return $elements;
        }

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $selector = trim((string) ($field['selector'] ?? ''));
            $type = strtolower(trim((string) ($field['type'] ?? '')));

            if ($selector === '' || !in_array($type, self::KNOWN_FIELD_TYPES, true)) {
                continue;
            }

            $value = isset($field['value']) ? (string) $field['value'] : null;

            $classification = $this->classifier->classify(
                $capability,
                true,
                $value !== null && $value !== ''
            );

            $elements[] = new Element(
                selector: $selector,
                type: $this->mapType($type),
                adapter: $this->name(),
                editability: $classification->level,
                editabilityReason: $classification->reason,
                cmsId: isset($field['id']) ? (string) $field['id'] : null,
                name: isset($field['label']) ? (string) $field['label'] : null,
                value: $value,
                meta: [
                    'legacy_id' => (string) ($field['id'] ?? ''),
                    'legacy_type' => $type,
                ],
            );
        }

        return $elements;
    }

    private function mapType(string $legacyType): string
    {
        return match ($legacyType) {
            'image', 'image-slot' => 'image',
            'attr' => 'link',
            'html' => 'html_component',
            default => 'paragraph',
        };
    }
}
