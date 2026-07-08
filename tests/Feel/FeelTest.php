<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Feel;

use Esdm\Generator\Feel\Feel;
use Esdm\Generator\Feel\FeelException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FeelTest extends TestCase
{
    public function testParsesComparisonsWithClockFunctions(): void
    {
        self::assertSame([
            't' => 'bin',
            'op' => '>=',
            'l' => ['t' => 'id', 'name' => 'validUntil'],
            'r' => ['t' => 'call', 'fn' => 'today'],
        ], Feel::parse('validUntil >= today()'));
    }

    public function testPrecedenceOrIsLooserThanAnd(): void
    {
        self::assertSame('or', Feel::parse('a = 1 and b = 2 or c = 3')['t']);
    }

    public function testParsesMembershipOverLiteralLists(): void
    {
        self::assertSame([
            't' => 'in',
            'e' => ['t' => 'id', 'name' => 'status'],
            'list' => [['t' => 'str', 'v' => 'sent'], ['t' => 'str', 'v' => 'drafted']],
        ], Feel::parse('status in ["sent", "drafted"]'));
    }

    public function testParsesNotAndParentheses(): void
    {
        self::assertSame([
            't' => 'not',
            'e' => ['t' => 'bin', 'op' => '=', 'l' => ['t' => 'id', 'name' => 'paid'], 'r' => ['t' => 'bool', 'v' => true]],
        ], Feel::parse('not (paid = true)'));
    }

    #[DataProvider('malformedExpressions')]
    public function testRejectsMalformedExpressions(string $source): void
    {
        $this->expectException(FeelException::class);
        Feel::parse($source);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedExpressions(): iterable
    {
        yield 'unclosed paren' => ['('];
        yield 'dangling operator' => ['a >'];
        yield 'unknown operator' => ['a ~ b'];
        yield 'unclosed list' => ['a in [1, 2'];
    }

    public function testValidateBindsIdentifiersAgainstAllowedFields(): void
    {
        $ast = Feel::parse('validUntil >= today() and status = "sent"');
        self::assertSame([], Feel::validate($ast, ['validUntil', 'status']));
        self::assertSame(['unknown field "validUntil"'], Feel::validate($ast, ['status']));
    }

    public function testCompilesToPhpOverTheInjectedIdentifierMapping(): void
    {
        $idToPhp = static fn (string $name): string => '$' . $name;

        $validUntil = Feel::compile(Feel::parse('validUntil >= today()'), $idToPhp);
        self::assertSame('($validUntil >= $today)', $validUntil['php']);
        self::assertTrue($validUntil['usesToday']);

        $membership = Feel::compile(Feel::parse('status in ["sent"] or total != 0'), $idToPhp);
        self::assertSame("(in_array(\$status, ['sent'], true) || (\$total != 0))", $membership['php']);

        $paid = Feel::compile(Feel::parse('paid = true'), $idToPhp);
        self::assertSame('($paid == true)', $paid['php']);
    }
}
