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
    public static function validate(array $ast, array $allowedFields, array $fieldTypes = []): array
    {
        $errors = [];
        self::bind($ast, $allowedFields, $errors);
        self::arithmetic($ast, $fieldTypes, $errors);

        return $errors;
    }

    private const ARITHMETIC = ['+', '-', '*', '/'];

    /**
     * The arithmetic gates of 0002's 2026-08-14 amendment: an operand declared `string` or
     * `boolean` is not arithmetic, and a literal zero divisor never is. An absent type is skipped.
     *
     * @param array<string, mixed> $node
     * @param array<string, string> $types
     * @param list<string> $errors
     */
    private static function arithmetic(array $node, array $types, array &$errors): void
    {
        $t = $node['t'] ?? '';
        if ($t === 'bin') {
            if (in_array($node['op'], self::ARITHMETIC, true)) {
                self::operand($node['l'], $types, $errors);
                self::operand($node['r'], $types, $errors);
                if ($node['op'] === '/' && ($node['r']['t'] ?? '') === 'num' && (float) $node['r']['v'] === 0.0) {
                    $errors[] = 'division by a literal zero';
                }
            }
            self::arithmetic($node['l'], $types, $errors);
            self::arithmetic($node['r'], $types, $errors);
        } elseif ($t === 'or' || $t === 'and') {
            self::arithmetic($node['l'], $types, $errors);
            self::arithmetic($node['r'], $types, $errors);
        } elseif ($t === 'not' || $t === 'neg') {
            self::arithmetic($node['e'], $types, $errors);
        } elseif ($t === 'in') {
            self::arithmetic($node['e'], $types, $errors);
            foreach ($node['list'] as $item) {
                self::arithmetic($item, $types, $errors);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, string> $types
     * @param list<string> $errors
     */
    private static function operand(array $node, array $types, array &$errors): void
    {
        if (($node['t'] ?? '') === 'id') {
            $type = $types[$node['name']] ?? null;
            if ($type === 'string' || $type === 'boolean') {
                $errors[] = sprintf('arithmetic on the %s field "%s"', $type, $node['name']);
            }
        }
        if (($node['t'] ?? '') === 'str' || ($node['t'] ?? '') === 'bool') {
            $errors[] = 'arithmetic on a ' . ($node['t'] === 'str' ? 'string' : 'boolean') . ' literal';
        }
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
            'bin' => self::binary($node, $idToPhp, $uses),
            'in' => 'in_array(' . self::emit($node['e'], $idToPhp, $uses) . ', ['
                . implode(', ', array_map(fn (array $x): string => self::emit($x, $idToPhp, $uses), $node['list']))
                . '], true)',
            'id' => $idToPhp($node['name']),
            'str' => var_export($node['v'], true),
            'num' => var_export($node['v'], true),
            'bool' => $node['v'] ? 'true' : 'false',
            'null' => 'null',
            'neg' => '-(' . self::emit($node['e'], $idToPhp, $uses) . ')',
            'call' => self::clockVar($node['fn'], $uses),
            default => 'null',
        };
    }

    /**
     * PHP's loose equality makes `0 == null` and `'' == null` both true, so a FEEL comparison
     * against the null literal must be identity - otherwise `amount = null` holds for a zero
     * amount. Everything else keeps loose equality, which is what makes an int field and a float
     * literal compare as FEEL expects.
     */
    /**
     * @param array<string, mixed> $node
     * @param array{today: bool, now: bool} $uses
     */
    private static function binary(array $node, \Closure $idToPhp, array &$uses): string
    {
        $left = self::emit($node['l'], $idToPhp, $uses);
        $right = self::emit($node['r'], $idToPhp, $uses);

        // FEEL yields null on a zero divisor and null in a predicate is false; NAN carries that,
        // since every comparison against NAN is false in PHP - and it avoids DivisionByZeroError.
        if ($node['op'] === '/') {
            return '((' . $right . ') == 0 ? NAN : ' . $left . ' / ' . $right . ')';
        }

        return '(' . $left . ' ' . self::phpOperator($node['op'], self::comparesToNull($node)) . ' ' . $right . ')';
    }

    private static function phpOperator(string $op, bool $againstNull = false): string
    {
        return match ($op) {
            '=' => $againstNull ? '===' : '==',
            '!=' => $againstNull ? '!==' : '!=',
            default => $op,
        };
    }

    /** @param array<string, mixed> $node */
    private static function comparesToNull(array $node): bool
    {
        return ($node['l']['t'] ?? null) === 'null' || ($node['r']['t'] ?? null) === 'null';
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
