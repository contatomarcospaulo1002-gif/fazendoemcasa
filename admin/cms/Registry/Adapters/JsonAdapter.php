<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry\Adapters;

require_once dirname(__DIR__, 2) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Registry\Adapter;
use FazendoEmCasa\Cms\Registry\Classifier;
use FazendoEmCasa\Cms\Registry\Element;

/**
 * Adaptador de JSON.
 *
 * Escrita determinística: `json_decode` → altera a chave → `json_encode`.
 * O caminho da chave é o selector (ex.: `hero.title`), o que o torna
 * único e reencontrável. Erros de JSON nunca são mascarados.
 */
final class JsonAdapter implements Adapter
{
    public function __construct(private readonly Classifier $classifier = new Classifier())
    {
    }

    public function name(): string
    {
        return 'json';
    }

    public function supportsPageKind(string $kind): bool
    {
        return $kind === 'json';
    }

    public function capability(): array
    {
        return [
            'canWrite' => true,
            'writeStrategy' => 'json_decode + alteração de chave + json_encode',
            'limitation' => null,
        ];
    }

    /** @return list<Element> */
    public function extract(array $analysis): array
    {
        $elements = [];
        $capability = $this->capability();

        // O scanner de JSON não expõe as chaves diretamente; quando
        // existirem (FASE 2 pode ser estendida), são percorridas aqui.
        $data = $analysis['json_data'] ?? null;
        if (!is_array($data)) {
            return $elements;
        }

        foreach ($this->flatten($data) as $path => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $textValue = (string) $value;
            $classification = $this->classifier->classify(
                $capability,
                $path !== '',
                $textValue !== ''
            );

            $elements[] = new Element(
                selector: $path,
                type: 'paragraph',
                adapter: $this->name(),
                editability: $classification->level,
                editabilityReason: $classification->reason,
                value: $textValue,
                meta: ['json_path' => $path],
            );
        }

        return $elements;
    }

    /**
     * @param array<string|int, mixed> $data
     * @return array<string, scalar>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out += $this->flatten($value, $path);
                continue;
            }
            $out[$path] = $value;
        }
        return $out;
    }
}
