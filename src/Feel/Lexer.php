<?php

declare(strict_types=1);

namespace Esdm\Generator\Feel;

/** Tokenizes the supported FEEL subset. */
final class Lexer
{
    /** @return list<array{type: string, value: string}> */
    public static function tokenize(string $source): array
    {
        // Anchored, ordered alternation; longest operators first.
        $pattern = '/(\s+)|(\d+(?:\.\d+)?)|("[^"]*")|(<=|>=|!=|=|<|>)|([\(\)\[\],])|([A-Za-z_][A-Za-z0-9_]*)/A';

        $tokens = [];
        $offset = 0;
        $length = strlen($source);

        while ($offset < $length) {
            if (!preg_match($pattern, $source, $m, 0, $offset)) {
                throw new FeelException(sprintf('Unexpected character at %d: "%s"', $offset, $source[$offset]));
            }
            $value = $m[0];
            $offset += strlen($value);

            if (trim($value) === '') {
                continue; // whitespace
            }

            $type = match (true) {
                (bool) preg_match('/^\d/', $value) => 'num',
                $value[0] === '"' => 'str',
                (bool) preg_match('/^[A-Za-z_]/', $value) => 'name',
                in_array($value, ['(', ')', '[', ']', ','], true) => 'punc',
                default => 'op',
            };

            $tokens[] = ['type' => $type, 'value' => $value];
        }

        $tokens[] = ['type' => 'eof', 'value' => ''];

        return $tokens;
    }
}
