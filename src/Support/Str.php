<?php

declare(strict_types=1);

namespace Esdm\Generator\Support;

/**
 * Naming helpers. ESDM identifiers are kebab-case (^[a-z][a-z0-9-]*$); generated
 * code needs StudlyCase classes, camelCase members and snake_case table names.
 */
final class Str
{
    /**
     * Methods a Symfony `AbstractController` already declares (several final/reserved).
     * A generated action that shadows one is a fatal redeclare, so its name is suffixed.
     * Stored lowercased for case-insensitive comparison.
     *
     * @var list<string>
     */
    private const RESERVED_CONTROLLER_METHODS = [
        'setcontainer', 'getsubscribedservices', 'has', 'get', 'getparameter',
        'generateurl', 'forward', 'redirect', 'redirecttoroute', 'json', 'file',
        'addflash', 'isgranted', 'denyaccessunlessgranted', 'createform',
        'createformbuilder', 'getuser', 'createnotfoundexception',
        'createaccessdeniedexception', 'renderview', 'render', 'renderform',
        'stream', 'dispatchmessage', 'addlink', 'sendearlyhints',
    ];

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

    /**
     * camelCase method name for a controller action, avoiding collisions with reserved
     * `AbstractController` methods (e.g. `get-user` -> `getUserAction`, not `getUser`).
     * Only actual collisions are suffixed, so route paths and other names stay stable.
     */
    public static function controllerAction(string $value): string
    {
        $method = self::camel($value);

        return in_array(strtolower($method), self::RESERVED_CONTROLLER_METHODS, true)
            ? $method . 'Action'
            : $method;
    }
}
