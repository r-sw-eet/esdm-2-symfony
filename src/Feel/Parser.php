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
        $left = $this->parsePrimary();
        $token = $this->peek();

        if ($token['type'] === 'op') {
            $this->advance();

            return ['t' => 'bin', 'op' => $token['value'], 'l' => $left, 'r' => $this->parsePrimary()];
        }

        if ($this->isKeyword('in')) {
            $this->advance();

            return ['t' => 'in', 'e' => $left, 'list' => $this->parseList()];
        }

        return $left;
    }

    /** @return list<array<string, mixed>> */
    private function parseList(): array
    {
        $this->eat('[');
        $items = [];
        if (!$this->at(']')) {
            $items[] = $this->parsePrimary();
            while ($this->at(',')) {
                $this->advance();
                $items[] = $this->parsePrimary();
            }
        }
        $this->eat(']');

        return $items;
    }

    /** @return array<string, mixed> */
    private function parsePrimary(): array
    {
        $token = $this->peek();

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
