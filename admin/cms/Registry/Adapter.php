<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Contrato único de adaptador.
 *
 * A regra de ouro: se o adaptador não consegue PROVAR que sabe escrever
 * de volta, ele mesmo declara `editable: false` no capability. O
 * Classifier então rebaixa o elemento para needs_adapter ou read_only.
 *
 * Nenhum adaptador nesta fase escreve em disco. `write()` é declarado
 * apenas para provar a capacidade; a escrita real entra na FASE 6 com
 * AtomicWriter + backup obrigatório.
 */
interface Adapter
{
    /** Identificador curto: html|php|css|json|component|legacy. */
    public function name(): string;

    /** Extensões/formatos que este adaptador reconhece. */
    public function supportsPageKind(string $kind): bool;

    /**
     * Capacidade declarada. Deve ser honesta: `canWrite` só é true se
     * exists mecanismo determinístico de escrita de volta.
     *
     * @return array{canWrite: bool, writeStrategy: string, limitation: ?string}
     */
    public function capability(): array;

    /**
     * Extrai os elementos de uma página já analisada.
     *
     * @param array<string, mixed> $analysis resultado cru do scanner
     * @return list<Element>
     */
    public function extract(array $analysis): array;
}
