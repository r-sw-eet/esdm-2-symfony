<?php

declare(strict_types=1);

namespace Esdm\Generator\Adapter\SymfonyEventSourcingDb;

use Esdm\Generator\Model\Field;

/** Maps JSON-Schema types to PHP type hints, PHP literals and payload casts. */
final class Types
{
    public static function scalarPhpType(Field $field): string
    {
        return match ($field->jsonType) {
            'string' => 'string',
            'boolean' => 'bool',
            'integer' => 'int',
            'number' => 'float',
            default => 'mixed',
        };
    }

    /**
     * The nullable form of a state property's type. `mixed` already includes
     * null, so it must NOT be prefixed with `?` (PHP 8 fatal: "Type mixed cannot
     * be marked as nullable"). Every other scalar becomes `?<type>`.
     */
    public static function nullablePhpType(Field $field): string
    {
        $type = self::scalarPhpType($field);

        return $type === 'mixed' ? 'mixed' : '?' . $type;
    }

    public static function defaultLiteral(Field $field): string
    {
        if ($field->hasDefault) {
            return var_export($field->default, true);
        }

        return match ($field->jsonType) {
            'string' => "''",
            'boolean' => 'false',
            'integer' => '0',
            'number' => '0.0',
            default => 'null',
        };
    }

    /** A cast of `$expr` (a payload lookup) to the field's PHP type, with fallback. */
    public static function payloadCast(Field $field, string $expr): string
    {
        return match ($field->jsonType) {
            'string' => '(string) (' . $expr . " ?? '')",
            'boolean' => '(bool) (' . $expr . ' ?? false)',
            'integer' => '(int) (' . $expr . ' ?? 0)',
            'number' => '(float) (' . $expr . ' ?? 0.0)',
            default => $expr . ' ?? null',
        };
    }

    public static function isBoolean(Field $field): bool
    {
        return $field->jsonType === 'boolean';
    }
}
