<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Model;

use Esdm\Generator\Model\ModelFactory;
use PHPUnit\Framework\TestCase;

final class ModelFactoryTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function documents(): array
    {
        return [
            ['kind' => 'domain', 'name' => 'shop'],
            ['kind' => 'bounded-context', 'name' => 'sales'],
            [
                'kind' => 'aggregate',
                'name' => 'order',
                'scope' => ['boundedContext' => 'sales'],
                'identifiedBy' => ['field' => 'order-id'],
                'state' => [
                    'type' => 'object',
                    'properties' => [
                        'order-id' => ['type' => 'string'],
                        'total' => ['type' => 'number', 'default' => 0],
                        'paid' => ['type' => 'boolean'],
                    ],
                    'required' => ['order-id'],
                ],
            ],
            [
                'kind' => 'command',
                'name' => 'place-order',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'data' => ['type' => 'object', 'properties' => ['total' => ['type' => 'number']], 'required' => ['total']],
                'publishes' => ['order-placed'],
            ],
            [
                'kind' => 'command',
                'name' => 'pay-order',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'data' => ['type' => 'object', 'properties' => ['order-id' => ['type' => 'string']], 'required' => ['order-id']],
                'publishes' => ['order-paid'],
                'metadata' => ['annotations' => ['esdm-extensions.io/lifecycle' => 'mutate']],
            ],
            [
                'kind' => 'event',
                'name' => 'order-placed',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'data' => [
                    'type' => 'object',
                    'properties' => ['order-id' => ['type' => 'string'], 'total' => ['type' => 'number']],
                    'required' => ['order-id', 'total'],
                ],
            ],
            [
                'kind' => 'event',
                'name' => 'order-paid',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'data' => ['type' => 'object', 'properties' => ['order-id' => ['type' => 'string']], 'required' => ['order-id']],
            ],
            [
                'kind' => 'state-machine',
                'name' => 'order-lifecycle',
                'scope' => ['boundedContext' => 'sales', 'aggregate' => 'order'],
                'initial' => 'placed',
                'states' => [['name' => 'placed'], ['name' => 'paid', 'final' => true]],
                'transitions' => [['on' => 'order-paid', 'to' => 'paid']],
                'admits' => [['command' => 'pay-order', 'from' => ['placed'], 'when' => 'total > 0']],
            ],
            [
                'kind' => 'read-model',
                'name' => 'order-list',
                'scope' => ['boundedContext' => 'sales'],
                'schema' => [
                    'type' => 'object',
                    'properties' => ['order-id' => ['type' => 'string'], 'total' => ['type' => 'number']],
                    'required' => ['order-id'],
                ],
                'projections' => [
                    ['aggregate' => 'order', 'event' => 'order-placed'],
                    ['aggregate' => 'order', 'event' => 'order-paid'],
                ],
            ],
            [
                'kind' => 'query',
                'name' => 'list-orders',
                'scope' => ['boundedContext' => 'sales'],
                'readModel' => 'order-list',
                'parameters' => [],
            ],
        ];
    }

    public function testWiresAggregatesCommandsEventsAndTheStateMachine(): void
    {
        $model = (new ModelFactory())->create(self::documents());

        self::assertSame('shop', $model->domain);
        $order = $model->aggregate('sales', 'order');
        self::assertNotNull($order);
        self::assertSame('order-id', $order->identityField);
        self::assertTrue($order->state->field('order-id')?->isIdentity);
        self::assertTrue($order->state->field('total')?->hasDefault);

        // place-order matches the create-verb heuristic; the event inherits it.
        self::assertSame(['create', 'mutate'], array_map(static fn ($c) => $c->lifecycle->value, $order->commands));
        self::assertSame('create', $order->event('order-placed')?->lifecycle->value);
        self::assertSame('mutate', $order->event('order-paid')?->lifecycle->value);
        self::assertSame('shop.order.order-placed', $order->event('order-placed')?->type);
        self::assertSame('order-placed', $order->createEvent()?->name);

        self::assertNotNull($order->stateMachine);
        self::assertSame('placed', $order->stateMachine->initial);
        self::assertSame('paid', $order->stateMachine->transitionTarget('order-paid'));
        self::assertSame('total > 0', $order->stateMachine->admitFor('pay-order')?->when);
        self::assertTrue($order->stateMachine->states[1]->final);
    }

    public function testWiresReadModelsAndQueriesIntoTheContext(): void
    {
        $model = (new ModelFactory())->create(self::documents());
        $sales = null;
        foreach ($model->boundedContexts as $context) {
            if ($context->name === 'sales') {
                $sales = $context;
            }
        }

        self::assertNotNull($sales);
        $readModel = $sales->readModel('order-list');
        self::assertNotNull($readModel);
        self::assertCount(2, $readModel->projections);
        self::assertTrue($readModel->projectsEvent('order-paid'));
        self::assertSame(['list-orders'], array_map(static fn ($query) => $query->name, $sales->queries));
    }

    public function testRejectsAModelWithoutADomainDocument(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('domain');
        (new ModelFactory())->create([['kind' => 'bounded-context', 'name' => 'x']]);
    }
}
