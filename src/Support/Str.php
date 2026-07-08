<?php

declare(strict_types=1);

namespace Esdm\Generator\Support;

/**
 * Naming helpers. ESDM identifiers are kebab-case (^[a-z][a-z0-9-]*$); generated
 * code needs StudlyCase classes, camelCase members and snake_case table names.
 */
final class Str
{
    public static function studly(string $value): string
    {
        $parts = preg_split('/[-_ ]+/', $value) ?: [];

        return implode('', array_map(static fn (string $p): string => ucfirst($p), $parts));
    }

    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    public static function snake(string $value): string
    {
        $value = str_replace('-', '_', $value);
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $value) ?? $value;

        return strtolower($value);
    }
}
