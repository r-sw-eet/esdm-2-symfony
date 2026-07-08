<?php

declare(strict_types=1);

namespace Esdm\Generator\Bpmn;

/**
 * Parses BPMN 2.0 XML (as produced by bpmn-js / Camunda Modeler) into a plain
 * array structure the {@see BpmnToEsdm} mapper consumes. Namespace-agnostic:
 * it matches by element local-name, so `bpmn:task` and `task` both work, and it
 * ignores diagram-interchange (`bpmndi`) entirely — only the semantic model is
 * read.
 *
 * ESDM-specific authoring hints ride in `extensionElements` as `<esdm:meta .../>`
 * (attributes become a key/value map) and `<esdm:field name=".." type=".."/>`
 * elements; `<camunda:property name value>` / `<property>` are read too so the
 * bpmn-js properties panel can drive the same mapping.
 */
final class BpmnParser
{
    private const TASK_TYPES = [
        'task', 'userTask', 'serviceTask', 'sendTask', 'receiveTask',
        'businessRuleTask', 'scriptTask', 'manualTask', 'callActivity',
    ];

    private const EVENT_TYPES = [
        'startEvent', 'endEvent', 'intermediateCatchEvent',
        'intermediateThrowEvent', 'boundaryEvent',
    ];

    private const GATEWAY_TYPES = [
        'exclusiveGateway', 'parallelGateway', 'inclusiveGateway',
        'eventBasedGateway', 'complexGateway',
    ];

    /** @return array{domain: ?string, processes: list<array<string, mixed>>, unmapped: list<string>} */
    public function parse(string $xml): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml);
        libxml_use_internal_errors($previous);
        if (!$ok) {
            throw new \RuntimeException('Could not parse BPMN XML.');
        }

        $xp = new \DOMXPath($dom);

        // collaboration: participant (pool) name per processRef — the context name.
        $participantName = [];
        foreach ($this->q($xp, "//*[local-name()='participant']") as $participant) {
            $ref = $participant->getAttribute('processRef');
            if ($ref !== '') {
                $participantName[$ref] = $participant->getAttribute('name');
            }
        }

        $definitionsMeta = [];
        foreach ($this->q($xp, "/*/*[local-name()='extensionElements']/*[local-name()='meta']") as $meta) {
            $definitionsMeta += $this->attributes($meta);
        }

        $processes = [];
        $unmapped = [];
        foreach ($this->q($xp, "//*[local-name()='process']") as $process) {
            $processes[] = $this->parseProcess($xp, $process, $participantName, $unmapped);
        }

        // Message flows across pools become ESDM policies (event in A → command in B).
        $messageFlows = [];
        foreach ($this->q($xp, "//*[local-name()='messageFlow']") as $flow) {
            $messageFlows[] = [
                'source' => $flow->getAttribute('sourceRef'),
                'target' => $flow->getAttribute('targetRef'),
                'name' => $flow->getAttribute('name'),
            ];
        }

        return [
            'domain' => $definitionsMeta['domain'] ?? null,
            'processes' => $processes,
            'messageFlows' => $messageFlows,
            'unmapped' => $unmapped,
        ];
    }

    /**
     * @param array<string, string> $participantName
     * @param list<string>          $unmapped
     * @return array<string, mixed>
     */
    private function parseProcess(\DOMXPath $xp, \DOMElement $process, array $participantName, array &$unmapped): array
    {
        $id = $process->getAttribute('id');
        $meta = [];
        foreach ($this->q($xp, "./*[local-name()='extensionElements']/*[local-name()='meta']", $process) as $m) {
            $meta += $this->attributes($m);
        }

        $nodes = [];
        foreach ($this->childElements($process) as $child) {
            $local = $child->localName;
            $kind = match (true) {
                in_array($local, self::TASK_TYPES, true) => 'task',
                in_array($local, self::EVENT_TYPES, true) => 'event',
                in_array($local, self::GATEWAY_TYPES, true) => 'gateway',
                default => null,
            };
            if ($kind === null) {
                if (!in_array($local, ['sequenceFlow', 'laneSet', 'extensionElements'], true)) {
                    $unmapped[] = sprintf('%s "%s"', $local, $child->getAttribute('id'));
                }
                continue;
            }

            $nodeMeta = [];
            $fields = [];
            foreach ($this->q($xp, "./*[local-name()='extensionElements']/*", $child) as $ext) {
                if ($ext->localName === 'meta') {
                    $nodeMeta += $this->attributes($ext);
                } elseif ($ext->localName === 'field') {
                    $fields[] = ['name' => $ext->getAttribute('name'), 'type' => $ext->getAttribute('type') ?: 'string'];
                } elseif ($ext->localName === 'property') {
                    $name = $ext->getAttribute('name');
                    if ($name !== '') {
                        $nodeMeta[$name] = $ext->getAttribute('value');
                    }
                }
            }

            $nodes[$child->getAttribute('id')] = [
                'id' => $child->getAttribute('id'),
                'name' => $child->getAttribute('name'),
                'kind' => $kind,
                'subtype' => $local,
                'meta' => $nodeMeta,
                'fields' => $fields,
            ];
        }

        $flows = [];
        foreach ($this->q($xp, "./*[local-name()='sequenceFlow']", $process) as $flow) {
            $condition = null;
            foreach ($this->q($xp, "./*[local-name()='conditionExpression']", $flow) as $cond) {
                $condition = $this->normalizeCondition($cond->textContent);
            }
            $flows[] = [
                'id' => $flow->getAttribute('id'),
                'source' => $flow->getAttribute('sourceRef'),
                'target' => $flow->getAttribute('targetRef'),
                'name' => $flow->getAttribute('name'),
                'condition' => $condition,
            ];
        }

        $lanes = [];
        foreach ($this->q($xp, ".//*[local-name()='lane']", $process) as $lane) {
            $laneMeta = [];
            foreach ($this->q($xp, "./*[local-name()='extensionElements']/*[local-name()='meta']", $lane) as $m) {
                $laneMeta += $this->attributes($m);
            }
            $refs = [];
            foreach ($this->q($xp, "./*[local-name()='flowNodeRef']", $lane) as $ref) {
                $refs[] = trim($ref->textContent);
            }
            $lanes[] = ['name' => $lane->getAttribute('name'), 'meta' => $laneMeta, 'refs' => $refs];
        }

        return [
            'id' => $id,
            'name' => $process->getAttribute('name') ?: ($participantName[$id] ?? $id),
            'context' => $meta['context'] ?? $participantName[$id] ?? null,
            'aggregate' => $meta['aggregate'] ?? null,
            'initial' => $meta['initial'] ?? null,
            'nodes' => $nodes,
            'flows' => $flows,
            'lanes' => $lanes,
        ];
    }

    private function normalizeCondition(string $raw): string
    {
        $text = trim($raw);
        // Strip a camunda-style ${ ... } / # { ... } expression wrapper.
        if (preg_match('/^[#$]\{(.*)\}$/s', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        return $text;
    }

    /** @return array<string, string> */
    private function attributes(\DOMElement $element): array
    {
        $out = [];
        foreach ($element->attributes as $attribute) {
            $out[$attribute->nodeName] = $attribute->nodeValue ?? '';
        }

        return $out;
    }

    /** @return list<\DOMElement> */
    private function childElements(\DOMElement $element): array
    {
        $out = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** @return list<\DOMElement> */
    private function q(\DOMXPath $xp, string $expression, ?\DOMNode $context = null): array
    {
        $out = [];
        $result = $context === null ? $xp->query($expression) : $xp->query($expression, $context);
        if ($result !== false) {
            foreach ($result as $node) {
                if ($node instanceof \DOMElement) {
                    $out[] = $node;
                }
            }
        }

        return $out;
    }
}
