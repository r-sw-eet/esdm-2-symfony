<?php

declare(strict_types=1);

namespace Esdm\Generator\Feel;

/**
 * Facade over the FEEL subset: parse, compile to a PHP boolean expression, and
 * validate (bind identifiers against a set of allowed fields). today()/now()
 * compile to injected `$today` / `$now` variables (ISO date / datetime strings).
 */
final class Feel
{
    /** @return array<string, mixed> */
    public static function parse(string $source): array
    {
        return Parser::parse($source);
    }

    /**
     * @param array<string, mixed> $ast
     * @param \Closure(string): string $idToPhp maps a field reference to PHP (e.g. $this->field)
     * @return array{php: string, usesToday: bool, usesNow: bool}
     */
    public static function compile(array $ast, \Closure $idToPhp): array
    {
        $uses = ['today' => false, 'now' => false];
        $php = self::emit($ast, $idToPhp, $uses);

        return ['php' => $php, 'usesToday' => $uses['today'], 'usesNow' => $uses['now']];
    }

    /**
     * @param array<string, mixed> $ast
     * @param list<string> $allowedFields
     * @return list<string> binding errors (empty = valid)
     */
    public static function validate(array $ast, array $allowedFields): array
    {
        $errors = [];
        self::bind($ast, $allowedFields, $errors);

        return $errors;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{today: bool, now: bool} $uses
     */
    private static function emit(array $node, \Closure $idToPhp, array &$uses): string
    {
        return match ($node['t']) {
            'or' => '(' . self::emit($node['l'], $idToPhp, $uses) . ' || ' . self::emit($node['r'], $idToPhp, $uses) . ')',
            'and' => '(' . self::emit($node['l'], $idToPhp, $uses) . ' && ' . self::emit($node['r'], $idToPhp, $uses) . ')',
            'not' => '!(' . self::emit($node['e'], $idToPhp, $uses) . ')',
            'bin' => '(' . self::emit($node['l'], $idToPhp, $uses) . ' ' . self::phpOperator($node['op']) . ' ' . self::emit($node['r'], $idToPhp, $uses) . ')',
            'in' => 'in_array(' . self::emit($node['e'], $idToPhp, $uses) . ', ['
                . implode(', ', array_map(fn (array $x): string => self::emit($x, $idToPhp, $uses), $node['list']))
                . '], true)',
            'id' => $idToPhp($node['name']),
            'str' => var_export($node['v'], true),
            'num' => var_export($node['v'], true),
            'bool' => $node['v'] ? 'true' : 'false',
            'call' => self::clockVar($node['fn'], $uses),
            default => 'null',
        };
    }

    private static function phpOperator(string $op): string
    {
        return match ($op) {
            '=' => '==',
            default => $op,
        };
    }

    /** @param array{today: bool, now: bool} $uses */
    private static function clockVar(string $fn, array &$uses): string
    {
        if ($fn === 'today') {
            $uses['today'] = true;

            return '$today';
        }

        $uses['now'] = true;

        return '$now';
    }

    /**
     * @param array<string, mixed> $node
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private static function bind(array $node, array $allowed, array &$errors): void
    {
        switch ($node['t']) {
            case 'id':
                if (!in_array($node['name'], $allowed, true)) {
                    $errors[] = sprintf('unknown field "%s"', $node['name']);
                }
                break;
            case 'or':
            case 'and':
            case 'bin':
                self::bind($node['l'], $allowed, $errors);
                self::bind($node['r'], $allowed, $errors);
                break;
            case 'not':
                self::bind($node['e'], $allowed, $errors);
                break;
            case 'in':
                self::bind($node['e'], $allowed, $errors);
                foreach ($node['list'] as $item) {
                    self::bind($item, $allowed, $errors);
                }
                break;
        }
    }
}
