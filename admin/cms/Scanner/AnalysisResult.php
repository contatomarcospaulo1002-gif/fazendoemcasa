<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Scanner;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Resultado de uma análise de arquivo.
 *
 * Estrutura imutável e serializável — é o contrato entre analisadores,
 * scanner e registry.
 */
final class AnalysisResult
{
    /**
     * @param list<array<string, mixed>> $elements
     * @param list<array<string, mixed>> $images
     * @param list<array<string, mixed>> $links
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly string $relPath,
        public readonly string $kind,
        public readonly string $contentHash,
        public readonly int $sizeBytes,
        public readonly ?string $title = null,
        public readonly array $elements = [],
        public readonly array $images = [],
        public readonly array $links = [],
        public readonly array $warnings = [],
        public readonly ?string $error = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null;
    }

    public function elementCount(): int
    {
        return count($this->elements);
    }

    public function imageCount(): int
    {
        return count($this->images);
    }

    public function linkCount(): int
    {
        return count($this->links);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rel_path' => $this->relPath,
            'kind' => $this->kind,
            'content_hash' => $this->contentHash,
            'size_bytes' => $this->sizeBytes,
            'title' => $this->title,
            'elements' => $this->elements,
            'images' => $this->images,
            'links' => $this->links,
            'warnings' => $this->warnings,
            'error' => $this->error,
        ];
    }
}
