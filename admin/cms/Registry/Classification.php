<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Resultado da classificação: nível + motivo sempre preenchido.
 *
 * O motivo nunca é vazio: a UI precisa de explicar ao utilizador porque
 * um campo não pode ser editado.
 */
final class Classification
{
    public function __construct(
        public readonly string $level,
        public readonly string $reason,
    ) {
        if (!Editability::isValid($this->level)) {
            throw new \InvalidArgumentException('Nível inválido: ' . $this->level);
        }
        if (trim($this->reason) === '') {
            throw new \InvalidArgumentException('Motivo de classificação é obrigatório.');
        }
    }

    public function isWritable(): bool
    {
        return Editability::isWritable($this->level);
    }
}
