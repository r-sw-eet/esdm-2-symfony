<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Bpmn;

use Esdm\Generator\Bpmn\BpmnParser;
use Esdm\Generator\Bpmn\BpmnToEsdm;
use PHPUnit\Framework\TestCase;

final class BpmnToEsdmTest extends TestCase
{
    private const BPMN = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <bpmn:definitions xmlns:bpmn="http://www.omg.org/spec/BPMN/20100524/MODEL" xmlns:esdm="https://esdm-extensions.io/bpmn">
          <bpmn:extensionElements><esdm:meta domain="widgets"/></bpmn:extensionElements>
          <bpmn:process id="Process_w" name="Widgets">
            <bpmn:extensionElements><esdm:meta context="widgets" aggregate="widget"/></bpmn:extensionElements>
            <bpmn:startEvent id="s"/>
            <bpmn:userTask id="t_create" name="create widget">
              <bpmn:extensionElements><esdm:meta state="created"/><esdm:field name="quantity" type="integer"/></bpmn:extensionElements>
            </bpmn:userTask>
            <bpmn:serviceTask id="t_ship" name="ship widget">
              <bpmn:extensionElements><esdm:meta state="shipped"/></bpmn:extensionElements>
            </bpmn:serviceTask>
            <bpmn:endEvent id="e"/>
            <bpmn:sequenceFlow id="f0" sourceRef="s" targetRef="t_create"/>
            <bpmn:sequenceFlow id="f1" sourceRef="t_create" targetRef="t_ship"><bpmn:conditionExpression>${quantity &gt;= 1}</bpmn:conditionExpression></bpmn:sequenceFlow>
            <bpmn:sequenceFlow id="f2" sourceRef="t_ship" targetRef="e"/>
          </bpmn:process>
        </bpmn:definitions>
        XML;

    public function testParsesBpmnNamespaceAgnosticallyAndPreservesDocumentOrder(): void
    {
        $parsed = (new BpmnParser())->parse(self::BPMN);

        self::assertSame('widgets', $parsed['domain']);
        self::assertCount(1, $parsed['processes']);
        $process = $parsed['processes'][0];
        self::assertSame('widgets', $process['context']);
        self::assertSame('widget', $process['aggregate']);
        // startEvent, two tasks, endEvent — kept in document order (mixed subtypes).
        self::assertSame(['s', 't_create', 't_ship', 'e'], array_keys($process['nodes']));
        // camunda ${...} wrapper stripped from the guard.
        $f1 = null;
        foreach ($process['flows'] as $flow) {
            if ($flow['id'] === 'f1') {
                $f1 = $flow;
            }
        }
        self::assertSame('quantity >= 1', $f1['condition']);
    }

    public function testMapsAPoolToCorePlusStateMachine(): void
    {
        $result = (new BpmnToEsdm())->map((new BpmnParser())->parse(self::BPMN), 'fallback');

        self::assertSame('widgets', $result['domain']);

        // A create command carries no id and pins its lifecycle annotation.
        $create = self::find($result['documents'], 'command', 'create-widget');
        self::assertSame(['annotations' => ['esdm-extensions.io/lifecycle' => 'create']], $create['metadata']);
        self::assertSame(['quantity'], $create['data']['required']);
        self::assertSame(['widget-created'], $create['publishes']);

        // A mutate command carries the aggregate id.
        $ship = self::find($result['documents'], 'command', 'ship-widget');
        self::assertSame(['id'], $ship['data']['required']);
        self::assertSame(['widget-shipped'], $ship['publishes']);

        // The event derives its past-participle name and pins the resulting status.
        $created = self::find($result['documents'], 'event', 'widget-created');
        self::assertSame(['type' => 'string', 'default' => 'created'], $created['data']['properties']['status']);

        // One state machine for the pool.
        self::assertCount(1, $result['stateMachines']);
    }

    /**
     * @param list<array<string, mixed>> $documents
     * @return array<string, mixed>
     */
    private static function find(array $documents, string $kind, string $name): array
    {
        foreach ($documents as $document) {
            if (($document['kind'] ?? null) === $kind && ($document['name'] ?? null) === $name) {
                return $document;
            }
        }
        self::fail("expected a {$kind} named {$name}");
    }
}
