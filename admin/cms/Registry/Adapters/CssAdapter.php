<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry\Adapters;

require_once dirname(__DIR__, 2) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Registry\Adapter;
use FazendoEmCasa\Cms\Registry\Classifier;
use FazendoEmCasa\Cms\Registry\Element;

/**
 * Adaptador de CSS.
 *
 * Sabe escrever URLs de `url()` por offset — mas só quando o offset é
 * conhecido. Nesta fase o scanner não guarda offsets, por isso declara
 * `canWrite: false` e os elementos caem para read_only.
 *
 * As URLs extraídas servem ao mapa de dependências da FASE 5.
 */
final class CssAdapter implements Adapter
{
    public function __construct(private readonly Classifier $classifier = new Classifier())
    {
    }

    public function name(): string
    {
        return 'css';
    }

    public function supportsPageKind(string $kind): bool
    {
        return $kind === 'css';
    }

    public function capability(): array
    {
        return [
            'canWrite' => false,
            'writeStrategy' => 'substituição de url() por offset',
            'limitation' => 'Offsets de url() ainda não são guardados pelo scanner.',
        ];
    }

    /** @return list<Element> */
    public function extract(array $analysis): array
    {
        $elements = [];
        $capability = $this->capability();

        foreach (($analysis['links'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $href = trim((string) ($raw['href'] ?? ''));
            if ($href === '') {
                continue;
            }

            $classification = $this->classifier->classify($capability, false, true);

            $elements[] = new Element(
                selector: 'url(' . $href . ')',
                type: 'background',
                adapter: $this->name(),
                editability: $classification->level,
                editabilityReason: $classification->reason,
                value: $href,
                meta: ['kind' => 'css_url'],
            );
        }

        return $elements;
    }
}
