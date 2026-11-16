<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Um elemento de conteúdo extraído de uma página.
 *
 * Imutável. O nível de editabilidade chega calculado pelo Classifier —
 * o elemento nunca decide sozinho que é editável.
 */
final class Element
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $selector,
        public readonly string $type,
        public readonly string $adapter,
        public readonly string $editability,
        public readonly string $editabilityReason,
        public readonly ?string $cmsId = null,
        public readonly ?string $section = null,
        public readonly ?string $name = null,
        public readonly ?string $value = null,
        public readonly ?int $originLine = null,
        public readonly array $meta = [],
    ) {
    }

    public function isWritable(): bool
    {
        return Editability::isWritable($this->editability);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'selector' => $this->selector,
            'type' => $this->type,
            'adapter' => $this->adapter,
            'editability' => $this->editability,
            'editability_reason' => $this->editabilityReason,
            'cms_id' => $this->cmsId,
            'section' => $this->section,
            'name' => $this->name,
            'value_current' => $this->value,
            'origin_line' => $this->originLine,
            'meta' => $this->meta,
        ];
    }
}
