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

    private const ARITY = [
        'today' => 0, 'now' => 0, 'date' => 1, 'duration' => 1,
        'starts with' => 2, 'ends with' => 2, 'contains' => 2, 'count' => 1, 'sum' => 1,
    ];

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
        } elseif ($t === 'call') {
            foreach ($node['args'] ?? [] as $argument) {
                self::arithmetic($argument, $types, $errors);
            }
        } elseif ($t === 'quant') {
            self::arithmetic($node['collection'], $types, $errors);
            self::arithmetic($node['predicate'], $types, $errors);
        } elseif ($t === 'cond') {
            self::arithmetic($node['c'], $types, $errors);
            self::arithmetic($node['a'], $types, $errors);
            self::arithmetic($node['b'], $types, $errors);
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
    private static function emit(array $node, \Closure $idToPhp, array &$uses, ?string $local = null): string
    {
        return match ($node['t']) {
            'or' => '(' . self::emit($node['l'], $idToPhp, $uses, $local) . ' || ' . self::emit($node['r'], $idToPhp, $uses, $local) . ')',
            'and' => '(' . self::emit($node['l'], $idToPhp, $uses, $local) . ' && ' . self::emit($node['r'], $idToPhp, $uses, $local) . ')',
            'not' => '!(' . self::emit($node['e'], $idToPhp, $uses, $local) . ')',
            'bin' => self::binary($node, $idToPhp, $uses, $local),
            'in' => 'in_array(' . self::emit($node['e'], $idToPhp, $uses, $local) . ', ['
                . implode(', ', array_map(fn (array $x): string => self::emit($x, $idToPhp, $uses, $local), $node['list']))
                . '], true)',
            'id' => $node['name'] === $local ? '$' . $local : $idToPhp($node['name']),
            // an object-typed field arrives as an array, so a property is a lookup
            'path' => '(((' . self::emit($node['target'], $idToPhp, $uses, $local) . ')['
                . var_export($node['property'], true) . '] ?? null))',
            'quant' => self::quantified($node, $idToPhp, $uses, $local),
            'str' => var_export($node['v'], true),
            'num' => var_export($node['v'], true),
            'bool' => $node['v'] ? 'true' : 'false',
            'null' => 'null',
            'neg' => '-(' . self::emit($node['e'], $idToPhp, $uses, $local) . ')',
            'cond' => '(' . self::emit($node['c'], $idToPhp, $uses, $local) . ' ? '
                . self::emit($node['a'], $idToPhp, $uses, $local) . ' : '
                . self::emit($node['b'], $idToPhp, $uses, $local) . ')',
            'call' => self::call($node, $idToPhp, $uses, $local),
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
    private static function binary(array $node, \Closure $idToPhp, array &$uses, ?string $local = null): string
    {
        $left = self::emit($node['l'], $idToPhp, $uses, $local);
        $right = self::emit($node['r'], $idToPhp, $uses, $local);

        // FEEL yields null on a zero divisor and null in a predicate is false; NAN carries that,
        // since every comparison against NAN is false in PHP - and it avoids DivisionByZeroError.
        if ($node['op'] === '/') {
            return '((' . $right . ') == 0 ? NAN : ' . $left . ' / ' . $right . ')';
        }

        // `validUntil + duration("P14D")` is a date shift, not a sum.
        if (in_array($node['op'], ['+', '-'], true) && ($node['r']['fn'] ?? '') === 'duration') {
            $sign = $node['op'] === '+' ? '+' : '-';

            return "date('Y-m-d', strtotime(" . $left . ') ' . $sign . ' ' . $right . ' * 86400)';
        }

        return '(' . $left . ' ' . self::phpOperator($node['op'], self::comparesToNull($node)) . ' ' . $right . ')';
    }

    /**
     * A duration is always a literal, so its day count is computed here rather than by emitted
     * code. Weeks are days; months and years are not, since their length depends on the date.
     *
     * @param array<string, mixed> $node
     */
    public static function durationDays(array $node): int
    {
        if (($node['t'] ?? '') !== 'str') {
            throw new FeelException('duration() takes a string literal');
        }
        if (preg_match('/^P(\d+)([DW])$/', $node['v'], $m) !== 1) {
            throw new FeelException(sprintf('unsupported duration "%s" - use P<n>D or P<n>W', $node['v']));
        }

        return (int) $m[1] * ($m[2] === 'W' ? 7 : 1);
    }

    /**
     * @param array<string, mixed> $node
     * @param array{today: bool, now: bool} $uses
     */
    private static function call(array $node, \Closure $idToPhp, array &$uses, ?string $local = null): string
    {
        if ($node['fn'] === 'today' || $node['fn'] === 'now') {
            return self::clockVar($node['fn'], $uses);
        }

        $args = array_map(fn (array $a): string => self::emit($a, $idToPhp, $uses, $local), $node['args']);

        return match ($node['fn']) {
            // an ISO-8601 date is already this family's wire form, so date() only normalises it
            'date' => "date('Y-m-d', strtotime(" . $args[0] . '))',
            'duration' => (string) self::durationDays($node['args'][0]),
            'count' => 'count(' . $args[0] . ' ?? [])',
            'sum' => 'array_sum(' . $args[0] . ' ?? [])',
            'starts with' => 'str_starts_with((string) ' . $args[0] . ', (string) ' . $args[1] . ')',
            'ends with' => 'str_ends_with((string) ' . $args[0] . ', (string) ' . $args[1] . ')',
            default => 'str_contains((string) ' . $args[0] . ', (string) ' . $args[1] . ')',
        };
    }

    /**
     * @param array<string, mixed> $node
     * @param array{today: bool, now: bool} $uses
     */
    private static function quantified(array $node, \Closure $idToPhp, array &$uses, ?string $local): string
    {
        $collection = self::emit($node['collection'], $idToPhp, $uses, $local);
        $predicate = self::emit($node['predicate'], $idToPhp, $uses, $node['variable']);
        $callback = 'static fn ($' . $node['variable'] . '): bool => ' . $predicate;

        return $node['every']
            ? 'array_reduce(' . $collection . ', static fn ($c, $i) => $c && (' . $callback . ')($i), true)'
            : 'array_reduce(' . $collection . ', static fn ($c, $i) => $c || (' . $callback . ')($i), false)';
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
            case 'neg':
                self::bind($node['e'], $allowed, $errors);
                break;
            case 'cond':
                self::bind($node['c'], $allowed, $errors);
                self::bind($node['a'], $allowed, $errors);
                self::bind($node['b'], $allowed, $errors);
                break;
            case 'path':
                self::bind($node['target'], $allowed, $errors);
                break;
            case 'quant':
                self::bind($node['collection'], $allowed, $errors);
                // the variable is in scope for the predicate only
                self::bind($node['predicate'], [...$allowed, $node['variable']], $errors);
                break;
            case 'in':
                self::bind($node['e'], $allowed, $errors);
                foreach ($node['list'] as $item) {
                    self::bind($item, $allowed, $errors);
                }
                break;
            case 'call':
                $arity = self::ARITY[$node['fn']] ?? null;
                if ($arity === null) {
                    $errors[] = sprintf('unknown function "%s"', $node['fn']);
                } elseif ($arity !== count($node['args'] ?? [])) {
                    $errors[] = sprintf(
                        '%s takes %d argument%s, got %d',
                        $node['fn'],
                        $arity,
                        $arity === 1 ? '' : 's',
                        count($node['args'] ?? []),
                    );
                }
                foreach ($node['args'] ?? [] as $argument) {
                    self::bind($argument, $allowed, $errors);
                }
                break;
        }
    }
}
