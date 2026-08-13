<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Feel;

use Esdm\Generator\Feel\FeelException;
use Esdm\Generator\Feel\Mapping;
use PHPUnit\Framework\TestCase;

/** The reaction payload mapping of extension proposal 0005. */
final class MappingTest extends TestCase
{
    public function testParsesEntriesInAuthorOrder(): void
    {
        $mapping = Mapping::parse('{ requestId: id, product: product }');

        self::assertSame(['requestId', 'product'], array_keys($mapping));
        self::assertSame('id', $mapping['requestId']['name'] ?? null);
    }

    public function testKeepsCommasInsideANestedExpression(): void
    {
        $mapping = Mapping::parse('{ tier: status in ["gold", "silver"], id: id }');

        self::assertSame(['tier', 'id'], array_keys($mapping));
        self::assertSame('in', $mapping['tier']['t'] ?? null);
    }

    public function testBindsValuesAgainstTheHandledEventFields(): void
    {
        $mapping = Mapping::parse('{ requestId: id, name: customerName }');

        self::assertSame([], Mapping::validate($mapping, ['id', 'customerName']));
        self::assertSame(['name: unknown field "customerName"'], Mapping::validate($mapping, ['id']));
    }

    public function testRejectsAnythingThatIsNotAContextLiteral(): void
    {
        $this->expectException(FeelException::class);
        $this->expectExceptionMessageMatches('/context literal/');

        Mapping::parse('requestId: id');
    }

    public function testRejectsADuplicateKey(): void
    {
        $this->expectException(FeelException::class);
        $this->expectExceptionMessageMatches('/twice/');

        Mapping::parse('{ id: id, id: product }');
    }

    public function testRejectsAnEmptyContext(): void
    {
        $this->expectException(FeelException::class);
        $this->expectExceptionMessageMatches('/at least one field/');

        Mapping::parse('{ }');
    }
}
