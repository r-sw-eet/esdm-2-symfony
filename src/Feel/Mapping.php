<?php

declare(strict_types=1);

namespace Esdm\Generator\Feel;

/**
 * A FEEL context literal - `{ requestId: id, name: customerName }` - as used by extension
 * proposal 0005 to say what a reaction's emitted command carries. Values are ordinary FEEL
 * expressions bound against the handled event's payload, so the expression language comes from
 * {@see Feel} and this class only splits the context into its entries.
 */
final class Mapping
{
    /**
     * @return array<string, array<string, mixed>> key => value expression AST, in author order
     */
    public static function parse(string $source): array
    {
        $body = trim($source);
        if (!str_starts_with($body, '{') || !str_ends_with($body, '}')) {
            throw new FeelException('A mapping must be a FEEL context literal: { key: expression, ... }');
        }

        $entries = [];
        foreach (self::splitTopLevel(substr($body, 1, -1)) as $entry) {
            if (trim($entry) === '') {
                continue;
            }
            $colon = self::colonAt($entry);
            if ($colon < 0) {
                throw new FeelException(sprintf('Mapping entry is not "key: expression": "%s"', trim($entry)));
            }
            $key = trim(substr($entry, 0, $colon));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw new FeelException(sprintf('Mapping key is not a field name: "%s"', $key));
            }
            if (isset($entries[$key])) {
                throw new FeelException(sprintf('Mapping assigns "%s" twice', $key));
            }
            $entries[$key] = Feel::parse(substr($entry, $colon + 1));
        }

        if ($entries === []) {
            throw new FeelException('A mapping must assign at least one field');
        }

        return $entries;
    }

    /**
     * @param array<string, array<string, mixed>> $mapping
     * @param list<string> $allowedFields
     * @return list<string> binding errors, prefixed with the key they came from
     */
    public static function validate(array $mapping, array $allowedFields): array
    {
        $errors = [];
        foreach ($mapping as $key => $value) {
            foreach (Feel::validate($value, $allowedFields) as $error) {
                $errors[] = $key . ': ' . $error;
            }
        }

        return $errors;
    }

    /**
     * Splits on top-level commas only, so a nested list or call keeps its own separators.
     *
     * @return list<string>
     */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $depth = 0;
        $inString = false;
        $start = 0;

        for ($i = 0, $len = strlen($body); $i < $len; $i++) {
            $c = $body[$i];
            if ($c === '"') {
                $inString = !$inString;
            } elseif (!$inString && ($c === '(' || $c === '[' || $c === '{')) {
                $depth++;
            } elseif (!$inString && ($c === ')' || $c === ']' || $c === '}')) {
                $depth--;
            } elseif (!$inString && $depth === 0 && $c === ',') {
                $parts[] = substr($body, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($body, $start);

        return $parts;
    }

    /** The key separator, skipping any colon inside a nested expression or a string. */
    private static function colonAt(string $entry): int
    {
        $depth = 0;
        $inString = false;

        for ($i = 0, $len = strlen($entry); $i < $len; $i++) {
            $c = $entry[$i];
            if ($c === '"') {
                $inString = !$inString;
            } elseif (!$inString && ($c === '(' || $c === '[' || $c === '{')) {
                $depth++;
            } elseif (!$inString && ($c === ')' || $c === ']' || $c === '}')) {
                $depth--;
            } elseif (!$inString && $depth === 0 && $c === ':') {
                return $i;
            }
        }

        return -1;
    }
}
