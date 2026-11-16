<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Registry;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Os quatro níveis de editabilidade.
 *
 * O nível é sempre CALCULADO a partir de evidência concreta (adaptador
 * declara se sabe escrever, o elemento tem referência estável, etc.).
 * Nunca é presumido a partir do tipo de ficheiro.
 */
final class Editability
{
    /** Existe mecanismo seguro e determinístico de escrita. */
    public const EDITABLE = 'editable';

    /** Estrutura reconhecida, mas a escrita exige adaptador específico. */
    public const NEEDS_ADAPTER = 'needs_adapter';

    /** Não há como alterar com segurança. */
    public const READ_ONLY = 'read_only';

    /** O scanner viu, mas não classificou. */
    public const UNCONFIGURED = 'unconfigured';

    public const ALL = [
        self::EDITABLE,
        self::NEEDS_ADAPTER,
        self::READ_ONLY,
        self::UNCONFIGURED,
    ];

    /** Níveis em que a escrita é permitida (com ou sem adaptador). */
    public const WRITABLE = [
        self::EDITABLE,
        self::NEEDS_ADAPTER,
    ];

    public static function isValid(string $level): bool
    {
        return in_array($level, self::ALL, true);
    }

    public static function isWritable(string $level): bool
    {
        return in_array($level, self::WRITABLE, true);
    }

    /** Rótulo para a UI. */
    public static function label(string $level): string
    {
        return match ($level) {
            self::EDITABLE => 'Editável',
            self::NEEDS_ADAPTER => 'Requer adaptador',
            self::READ_ONLY => 'Somente leitura',
            self::UNCONFIGURED => 'Não configurado',
            default => 'Desconhecido',
        };
    }
}
