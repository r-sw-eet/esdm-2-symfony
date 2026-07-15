<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Adapter;

use Esdm\Generator\Adapter\SymfonyEventSourcingDb\SymfonyEventSourcingDbAdapter;
use Esdm\Generator\Adapter\SymfonyPatchlevelPostgres\SymfonyPatchlevelPostgresAdapter;
use Esdm\Generator\Model\Model;
use Esdm\Generator\Model\ModelFactory;
use PHPUnit\Framework\TestCase;

/**
 * A GWT scenario asserts what it declares. A real event carries more than a `then`
 * block states — a state-machine status, echoed command inputs — so the emitter
 * must NOT fabricate a value for an undeclared field (an over-assert that fails
 * spuriously on every richer model). This fixture declares `id` + `status` on the
 * `then` event but leaves `customerRef` implicit, which the create command still
 * sets, and pins each target's emitter to the subset behaviour.
 */
final class EmittedGwtOverAssertTest extends TestCase
{
    public function testEventSourcingDbAssertsOnlyDeclaredThenFields(): void
    {
        $files = (new SymfonyEventSourcingDbAdapter())
            ->generate(self::model(), ['namespace' => 'App', 'appName' => 'sales'])
            ->files();

        $test = $files['tests/Sales/OrderLifecycleTest.php'] ?? null;
        self::assertNotNull($test, 'expected an emitted GWT test');

        // The declared fields are asserted...
        self::assertStringContainsString("\$events[0]->data['id']", $test);
        self::assertStringContainsString("\$events[0]->data['status']", $test);
        // ...and the undeclared field is left to the decider — never asserted against
        // a fabricated default (the over-assert bug would emit `'customerRef' => ''`).
        self::assertStringNotContainsString('customerRef', $test);
    }

    public function testPatchlevelReconstructsUndeclaredThenFieldsInsteadOfNull(): void
    {
        $files = (new SymfonyPatchlevelPostgresAdapter())
            ->generate(self::model(), ['namespace' => 'App', 'appName' => 'sales'])
            ->files();

        $test = $files['tests/Sales/OrderLifecycleTest.php'] ?? null;
        self::assertNotNull($test, 'expected an emitted GWT test');

        // patchlevel's harness asserts released events by full equality, so the
        // undeclared `customerRef` must carry the value the aggregate records
        // (the command input 'REF-1'), not a fabricated null that would TypeError
        // a non-nullable constructor arg or fail the equality.
        self::assertStringContainsString("'REF-1', 'placed'", $test);
        self::assertStringNotContainsString('NULL', $test);
    }

    private static function model(): Model
    {
        $documents = [
            ['kind' => 'domain', 'name' => 'shop'],
            ['kind' => 'bounded-context', 'name' => 'sales'],
            [
                'kind' => 'aggregate',
                'name' => 'order',
                'scope' => ['boundedContext' => 'sales'],
                'identifiedBy' => ['field' => 'id'],
                'state' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'customerRef' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'default' => 'placed'],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'kind' => 'command',
                'name' => 'place-order',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'data' => ['type' => 'object', 'properties' => ['customerRef' => ['type' => 'string']], 'required' => ['customerRef']],
                'publishes' => ['order-placed'],
            ],
            [
                'kind' => 'event',
                'name' => 'order-placed',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'data' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'customerRef' => ['type' => 'string'],
                        'status' => ['type' => 'string', 'default' => 'placed'],
                    ],
                    'required' => ['id', 'customerRef', 'status'],
                ],
            ],
            [
                'kind' => 'state-machine',
                'name' => 'order-lifecycle',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'initial' => 'placed',
                'states' => [['name' => 'placed']],
                'transitions' => [['on' => 'order-placed', 'to' => 'placed']],
                'admits' => [],
            ],
            [
                'apiVersion' => 'schema.esdm.io/given-when-then/v1',
                'kind' => 'feature',
                'name' => 'order-lifecycle',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'scenarios' => [
                    [
                        'name' => 'place-an-order',
                        'when' => ['command' => 'place-order', 'data' => ['customerRef' => 'REF-1']],
                        'then' => ['events' => [
                            ['event' => 'order-placed', 'data' => ['id' => '00000000-0000-0000-0000-000000000001', 'status' => 'placed']],
                        ]],
                    ],
                ],
            ],
        ];

        return (new ModelFactory())->create($documents);
    }
}
