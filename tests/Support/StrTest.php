<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Support;

use Esdm\Generator\Support\Str;
use PHPUnit\Framework\TestCase;

final class StrTest extends TestCase
{
    public function testStudlyTurnsKebabCaseIntoStudlyCase(): void
    {
        self::assertSame('OrderItem', Str::studly('order-item'));
        self::assertSame('Task', Str::studly('task'));
        self::assertSame('QualityCheckPassedThing', Str::studly('quality-check_passed thing'));
    }

    public function testCamelTurnsKebabCaseIntoCamelCase(): void
    {
        self::assertSame('orderItem', Str::camel('order-item'));
        self::assertSame('validUntil', Str::camel('valid-until'));
        self::assertSame('id', Str::camel('id'));
    }

    public function testSnakeTurnsKebabCaseAndCamelCaseIntoSnakeCase(): void
    {
        self::assertSame('order_item', Str::snake('order-item'));
        self::assertSame('order_item', Str::snake('orderItem'));
        self::assertSame('rm2_widget', Str::snake('rm2Widget'));
    }
}
