<?php
declare(strict_types=1);

namespace FazendoEmCasa\Cms\Core;

require_once dirname(__DIR__) . '/_deny-direct.php';

/**
 * Helpers de JSON com erros explícitos.
 */
final class Json
{
    /** @param mixed $value */
    public static function encode(mixed $value, bool $pretty = false): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return json_encode($value, $flags);
    }

    /** @return array<string, mixed>|null */
    public static function decodeObject(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }

    /** @return list<mixed>|null */
    public static function decodeList(string $raw): ?array
    {
        $decoded = self::decodeObject($raw);
        if ($decoded === null) {
            return null;
        }
        return array_is_list($decoded) ? $decoded : null;
    }
}
