<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry;

require_once dirname(__DIR__) . '/_deny-direct.php';

use FazendoEmCasa\Cms\Registry\Adapters\ComponentAdapter;
use FazendoEmCasa\Cms\Registry\Adapters\CssAdapter;
use FazendoEmCasa\Cms\Registry\Adapters\HtmlAdapter;
use FazendoEmCasa\Cms\Registry\Adapters\JsonAdapter;
use FazendoEmCasa\Cms\Registry\Adapters\PhpAdapter;

/**
 * Escolhe o(s) adaptador(es) aplicável(is) a uma página.
 *
 * Uma página HTML tem sempre dois adaptadores candidatos: `html`
 * (estrutura) e `component` (contrato data-cms-*). O Registry aplica
 * ambos e deduplica por selector, ficando com a classificação mais
 * permissiva PROVADA — nunca com a mais otimista por omissão.
 */
final class AdapterRegistry
{
    /** @var list<Adapter> */
    private array $adapters;

    /** @param list<Adapter>|null $adapters */
    public function __construct(?array $adapters = null)
    {
        $this->adapters = $adapters ?? [
            new HtmlAdapter(),
            new ComponentAdapter(),
            new PhpAdapter(),
            new CssAdapter(),
            new JsonAdapter(),
        ];
    }

    /** @return list<Adapter> */
    public function forKind(string $kind): array
    {
        $out = [];
        foreach ($this->adapters as $adapter) {
            if ($adapter->supportsPageKind($kind)) {
                $out[] = $adapter;
            }
        }
        return $out;
    }

    /** @return list<Adapter> */
    public function all(): array
    {
        return $this->adapters;
    }

    public function byName(string $name): ?Adapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->name() === $name) {
                return $adapter;
            }
        }
        return null;
    }
}
