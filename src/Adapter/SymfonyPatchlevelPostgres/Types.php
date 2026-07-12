<?php

declare(strict_types=1);

namespace Esdm\Generator\Adapter\SymfonyPatchlevelPostgres;

use Esdm\Generator\Model\Field;

/** Maps JSON-Schema types to PHP type hints, PHP literals and PostgreSQL columns. */
final class Types
{
    public const UUID = 'Patchlevel\\EventSourcing\\Aggregate\\Uuid';

    public static function phpType(Field $field, bool $identityAsUuid): string
    {
        if ($field->isIdentity && $identityAsUuid) {
            return 'Uuid';
        }

        return match ($field->jsonType) {
            'string' => 'string',
            'boolean' => 'bool',
            'integer' => 'int',
            'number' => 'float',
            default => 'mixed',
        };
    }

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

    public static function pgColumn(Field $field): string
    {
        return match ($field->jsonType) {
            'boolean' => 'BOOLEAN',
            'integer' => 'INTEGER',
            'number' => 'DOUBLE PRECISION',
            default => 'VARCHAR(255)',
        };
    }

    public static function isBoolean(Field $field): bool
    {
        return $field->jsonType === 'boolean';
    }

    public static function isInteger(Field $field): bool
    {
        return $field->jsonType === 'integer';
    }

    public static function isNumber(Field $field): bool
    {
        return $field->jsonType === 'number';
    }
}
