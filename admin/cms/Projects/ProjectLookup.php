<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Projects;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Consulta mínima que o DiscoveryEngine precisa.
 *
 * Interface pequena de propósito: permite testar o discovery sem banco.
 */
interface ProjectLookup
{
    /** @return array<string, mixed>|null */
    public function findByPath(string $relPath): ?array;
}
