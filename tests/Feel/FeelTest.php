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

    public function testNullIsALiteralAndNotAFieldName(): void
    {
        $ast = Feel::parse('cancelledAt = null');

        self::assertSame('null', $ast['r']['t']);
        // `null` used to lex as a name, so this reported: unknown field "null".
        self::assertSame([], Feel::validate($ast, ['cancelledAt']));
    }

    public function testComparisonAgainstNullIsIdentityNotLooseEquality(): void
    {
        // PHP says 0 == null, so a loose comparison would make `amount = null` true for zero.
        $compiled = Feel::compile(Feel::parse('amount = null'), static fn (string $n): string => '$this->' . $n);

        self::assertStringContainsString('===', $compiled['php']);
    }

    public function testANegativeLiteralFoldsSoTheEmittedCodeReadsNaturally(): void
    {
        self::assertSame(['t' => 'num', 'v' => -1], Feel::parse('amount > -1')['r']);
    }

    public function testBetweenAndRangesDesugarIntoTwoComparisons(): void
    {
        $expected = Feel::parse('qty >= 1 and qty <= 10');

        self::assertSame($expected, Feel::parse('qty between 1 and 10'));
        self::assertSame($expected, Feel::parse('qty in [1..10]'));
    }

    public function testMembershipStaysMembership(): void
    {
        self::assertSame('in', Feel::parse('status in ["a", "b"]')['t']);
    }

    public function testArithmeticPrecedenceAndSafeDivision(): void
    {
        self::assertSame(Feel::parse('x = 1 + (2 * 3)'), Feel::parse('x = 1 + 2 * 3'));

        $compiled = Feel::compile(Feel::parse('total / count > 1'), static fn (string $n): string => '$this->' . $n);
        // a zero divisor must not raise DivisionByZeroError: NAN makes the comparison false
        self::assertStringContainsString('NAN', $compiled['php']);
    }

    public function testConditionalsParseBindAndCompile(): void
    {
        $expression = 'if quantity > 1 then amount * quantity >= 5000 else amount >= 99999';

        self::assertSame('cond', Feel::parse($expression)['t']);
        self::assertSame(
            [],
            Feel::validate(Feel::parse($expression), ['amount', 'quantity'], ['amount' => 'number', 'quantity' => 'integer']),
        );

        $compiled = Feel::compile(Feel::parse($expression), static fn (string $n): string => '$this->' . $n);
        self::assertStringContainsString(' ? ', $compiled['php']);
    }

    public function testAConditionalWithoutAnElseIsRejected(): void
    {
        $this->expectException(FeelException::class);

        Feel::parse('if a then b');
    }

    public function testCallsCarryArgumentsAndTheirArityIsChecked(): void
    {
        self::assertSame([], Feel::validate(Feel::parse('contains(product, "c")'), ['product']));
        self::assertSame(
            ['starts with takes 2 arguments, got 1'],
            Feel::validate(Feel::parse('starts with(product)'), ['product']),
        );
    }

    public function testDateArithmeticIsAShiftAndTheDurationIsResolvedHere(): void
    {
        $resolver = static fn (string $n): string => '$this->' . $n;

        // a duration is a literal, so its day count is computed at generation time: two weeks is 14
        $compiled = Feel::compile(Feel::parse('validUntil + duration("P2W") >= today()'), $resolver);
        self::assertStringContainsString('14 * 86400', $compiled['php']);

        $this->expectException(FeelException::class);
        Feel::compile(Feel::parse('validUntil + duration("P1M") >= today()'), $resolver);
    }
}
