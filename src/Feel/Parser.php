<?php

declare(strict_types=1);

namespace Esdm\Generator\Feel;

/**
 * Recursive-descent parser for the FEEL subset (proposal 0002). Produces an
 * array AST. Precedence: or < and < comparison < primary.
 *
 * Supported: comparisons (= != < <= > >=), and/or/not(...), membership
 * (x in [a, b]), parentheses, string/number/boolean literals, identifiers
 * (field references) and the niladic functions today()/now().
 */
final class Parser
{
    private const COMPARISONS = ['=', '!=', '<', '<=', '>', '>='];

    private int $i = 0;

    /** @var list<array{type: string, value: string}> */
    private array $tokens = [];

    /** @return array<string, mixed> */
    public static function parse(string $source): array
    {
        $parser = new self();
        $parser->tokens = Lexer::tokenize($source);
        $ast = $parser->parseOr();
        $parser->expectType('eof');

        return $ast;
    }

    /** @return array{type: string, value: string} */
    private function peek(): array
    {
        return $this->tokens[$this->i];
    }

    private function advance(): void
    {
        $this->i++;
    }

    private function at(string $value): bool
    {
        return $this->peek()['value'] === $value;
    }

    private function isKeyword(string $keyword): bool
    {
        $token = $this->peek();

        return $token['type'] === 'name' && strtolower($token['value']) === $keyword;
    }

    private function eat(string $value): void
    {
        if (!$this->at($value)) {
            throw new FeelException(sprintf('Expected "%s", got "%s"', $value, $this->peek()['value']));
        }
        $this->advance();
    }

    private function expectType(string $type): void
    {
        if ($this->peek()['type'] !== $type) {
            throw new FeelException(sprintf('Expected %s, got "%s"', $type, $this->peek()['value']));
        }
    }

    /** @return array<string, mixed> */
    private function parseOr(): array
    {
        // `if` sits at the lowest precedence, so its branches are whole expressions and it needs
        // no parentheses to hold them.
        if ($this->isKeyword('if')) {
            $this->advance();
            $condition = $this->parseOr();
            if (!$this->isKeyword('then')) {
                throw new FeelException('Expected "then" in a conditional');
            }
            $this->advance();
            $whenTrue = $this->parseOr();
            if (!$this->isKeyword('else')) {
                throw new FeelException('Expected "else" in a conditional');
            }
            $this->advance();

            return ['t' => 'cond', 'c' => $condition, 'a' => $whenTrue, 'b' => $this->parseOr()];
        }

        $left = $this->parseAnd();
        while ($this->isKeyword('or')) {
            $this->advance();
            $left = ['t' => 'or', 'l' => $left, 'r' => $this->parseAnd()];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function parseAnd(): array
    {
        $left = $this->parseComparison();
        while ($this->isKeyword('and')) {
            $this->advance();
            $left = ['t' => 'and', 'l' => $left, 'r' => $this->parseComparison()];
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function parseComparison(): array
    {
        $left = $this->parseAdditive();
        $token = $this->peek();

        if ($token['type'] === 'op' && in_array($token['value'], self::COMPARISONS, true)) {
            $this->advance();

            return ['t' => 'bin', 'op' => $token['value'], 'l' => $left, 'r' => $this->parseAdditive()];
        }

        if ($this->isKeyword('in')) {
            $this->advance();

            return $this->parseMembership($left);
        }

        // `x between a and b` is sugar for two comparisons; desugaring here keeps every
        // compiler in the family unaware that it exists.
        if ($this->isKeyword('between')) {
            $this->advance();
            $low = $this->parseAdditive();
            if (!$this->isKeyword('and')) {
                throw new FeelException('Expected "and" in a between expression');
            }
            $this->advance();

            return self::range($left, $low, $this->parseAdditive());
        }

        return $left;
    }

    /**
     * Left-associative, and binding tighter than any comparison.
     *
     * @return array<string, mixed>
     */
    private function parseAdditive(): array
    {
        $left = $this->parseMultiplicative();
        while ($this->at('+') || $this->at('-')) {
            $op = $this->peek()['value'];
            $this->advance();
            $left = ['t' => 'bin', 'op' => $op, 'l' => $left, 'r' => $this->parseMultiplicative()];
        }

        return $left;
    }

    /**
     * Binds tighter than `+` and `-`.
     *
     * @return array<string, mixed>
     */
    private function parseMultiplicative(): array
    {
        $left = $this->parsePrimary();
        while ($this->at('*') || $this->at('/')) {
            $op = $this->peek()['value'];
            $this->advance();
            $left = ['t' => 'bin', 'op' => $op, 'l' => $left, 'r' => $this->parsePrimary()];
        }

        return $left;
    }

    /**
     * `x in [a, b]` stays a membership test; `x in [1..10]` desugars to a range.
     *
     * @param array<string, mixed> $left
     * @return array<string, mixed>
     */
    private function parseMembership(array $left): array
    {
        $this->eat('[');
        if ($this->at(']')) {
            $this->eat(']');

            return ['t' => 'in', 'e' => $left, 'list' => []];
        }

        $first = $this->parsePrimary();
        if ($this->at('..')) {
            $this->advance();
            $high = $this->parsePrimary();
            $this->eat(']');

            return self::range($left, $first, $high);
        }

        $items = [$first];
        while ($this->at(',')) {
            $this->advance();
            $items[] = $this->parsePrimary();
        }
        $this->eat(']');

        return ['t' => 'in', 'e' => $left, 'list' => $items];
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, mixed> $low
     * @param array<string, mixed> $high
     * @return array<string, mixed>
     */
    private static function range(array $value, array $low, array $high): array
    {
        return [
            't' => 'and',
            'l' => ['t' => 'bin', 'op' => '>=', 'l' => $value, 'r' => $low],
            'r' => ['t' => 'bin', 'op' => '<=', 'l' => $value, 'r' => $high],
        ];
    }

    /** @return array<string, mixed> */
    private function parsePrimary(): array
    {
        $token = $this->peek();

        if ($this->at('-')) {
            $this->advance();
            if ($this->peek()['type'] === 'num') {
                $value = $this->peek()['value'];
                $this->advance();

                return ['t' => 'num', 'v' => -(str_contains($value, '.') ? (float) $value : (int) $value)];
            }

            return ['t' => 'neg', 'e' => $this->parsePrimary()];
        }

        if ($this->at('(')) {
            $this->advance();
            $expr = $this->parseOr();
            $this->eat(')');

            return $expr;
        }

        if ($this->isKeyword('not')) {
            $this->advance();
            $this->eat('(');
            $expr = $this->parseOr();
            $this->eat(')');

            return ['t' => 'not', 'e' => $expr];
        }

        if ($token['type'] === 'num') {
            $this->advance();

            return ['t' => 'num', 'v' => str_contains($token['value'], '.') ? (float) $token['value'] : (int) $token['value']];
        }

        if ($token['type'] === 'str') {
            $this->advance();

            return ['t' => 'str', 'v' => substr($token['value'], 1, -1)];
        }

        if ($token['type'] === 'name') {
            $name = $token['value'];
            $lower = strtolower($name);

            if ($lower === 'true' || $lower === 'false') {
                $this->advance();

                return ['t' => 'bool', 'v' => $lower === 'true'];
            }

            // Without this, `null` lexes as a name and binds as an unknown field.
            if ($lower === 'null') {
                $this->advance();

                return ['t' => 'null'];
            }

            if ($lower === 'today' || $lower === 'now') {
                $this->advance();
                $this->eat('(');
                $this->eat(')');

                return ['t' => 'call', 'fn' => $lower];
            }

            $this->advance();

            return ['t' => 'id', 'name' => $name];
        }

        throw new FeelException(sprintf('Unexpected token "%s"', $token['value']));
    }
}
