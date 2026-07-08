<?php

declare(strict_types=1);

namespace Esdm\Generator\Bpmn;

use Esdm\Generator\Feel\Feel;
use Esdm\Generator\Model\Lifecycle;

/**
 * Maps a parsed BPMN model (see {@see BpmnParser}) into ESDM documents:
 * core (domain, bounded-context, aggregate, events, commands, read-model,
 * queries), [0001] state machines and [0002] FEEL guards — the three-stream
 * decomposition of proposal 0003.
 *
 * Each pool/process becomes a bounded-context + aggregate; each task a command
 * and its event. Lifecycle states are taken from `esdm:meta state="…"`; a
 * command's admissible source states and final states are derived by walking
 * the sequence-flow graph; gateway/flow `conditionExpression`s become FEEL
 * guards. Things BPMN cannot express (state names, field types) ride on
 * `esdm:` extension hints; everything structural is read from the diagram.
 */
final class BpmnToEsdm
{
    private const PAST = [
        'add' => 'added', 'create' => 'created', 'register' => 'registered',
        'open' => 'opened', 'start' => 'started', 'submit' => 'submitted',
        'place' => 'placed', 'raise' => 'raised', 'issue' => 'issued',
        'request' => 'requested', 'draft' => 'drafted', 'pay' => 'paid',
        'price' => 'priced', 'send' => 'sent', 'accept' => 'accepted',
        'reject' => 'rejected', 'approve' => 'approved', 'ship' => 'shipped',
        'deliver' => 'delivered', 'complete' => 'completed', 'rename' => 'renamed',
        'update' => 'updated', 'change' => 'changed', 'set' => 'set',
        'cancel' => 'cancelled', 'close' => 'closed', 'delete' => 'deleted',
        'remove' => 'removed', 'archive' => 'archived', 'withdraw' => 'withdrawn',
        'discard' => 'discarded', 'confirm' => 'confirmed', 'fulfil' => 'fulfilled',
    ];

    private const API_CORE = 'schema.esdm.io/core/v1';
    private const API_SM = 'schema.esdm.io/state-machine/v1';

    /**
     * @param array{domain: ?string, processes: list<array<string, mixed>>, unmapped: list<string>} $parsed
     * @return array{domain: string, documents: list<array<string, mixed>>, stateMachines: list<array<string, mixed>>, notes: list<string>}
     */
    public function map(array $parsed, string $fallbackDomain): array
    {
        $domain = $this->slug($parsed['domain'] ?? '') ?: $fallbackDomain;
        $documents = [[
            'apiVersion' => self::API_CORE,
            'kind' => 'domain',
            'name' => $domain,
            'description' => 'Generated from a BPMN model (proposal 0003).',
        ]];
        $stateMachines = [];
        $notes = [];

        foreach ($parsed['unmapped'] as $element) {
            $notes[] = 'unmapped BPMN element: ' . $element;
        }

        // Phase 1 — resolve every process to (context, aggregate, tasks) and index
        // each node so cross-pool message flows can be wired into policies.
        $resolved = [];
        $nodeIndex = [];
        foreach ($parsed['processes'] as $process) {
            $r = $this->resolveProcess($process, $domain, $notes);
            $resolved[] = $r;
            foreach ($r['tasks'] as $id => $task) {
                $nodeIndex[$id] = ['context' => $r['context'], 'aggregate' => $r['aggregate'], 'task' => $task];
            }
        }

        // Phase 2 — emit core docs + state machine per process.
        foreach ($resolved as $r) {
            $this->emitProcess($r, $domain, $documents, $stateMachines, $notes);
        }

        // Phase 3 — message flow across pools → policy (event in A → command in B).
        foreach ($parsed['messageFlows'] ?? [] as $flow) {
            $this->emitPolicy($flow, $nodeIndex, $domain, $documents, $notes);
        }

        return ['domain' => $domain, 'documents' => $documents, 'stateMachines' => $stateMachines, 'notes' => $notes];
    }

    /**
     * Resolve a process to (context, aggregate, tasks, graph) without emitting —
     * so message-flow endpoints can be looked up across processes first.
     *
     * @param array<string, mixed> $process
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private function resolveProcess(array $process, string $domain, array &$notes): array
    {
        $context = $this->slug($process['context'] ?: $process['name']) ?: 'main';
        $aggregate = $this->slug($process['aggregate'] ?: $this->singular($context));
        $nodes = $process['nodes'];

        [$incoming, $outgoing] = $this->adjacency($process['flows']);

        $tasks = [];
        foreach ($nodes as $id => $node) {
            if ($node['kind'] !== 'task') {
                continue;
            }
            $command = $this->slug($node['name']);
            if ($command === '') {
                $notes[] = 'task without a name skipped: ' . $id;
                continue;
            }
            $lifecycle = $node['meta']['lifecycle'] ?? Lifecycle::fromName($command, null)->value;
            $tasks[$id] = [
                'id' => $id,
                'command' => $command,
                'lifecycle' => $lifecycle,
                'isCreate' => $lifecycle === Lifecycle::Create->value,
                'fields' => $node['fields'],
                'state' => $node['meta']['state'] ?? null,
                'event' => $this->slug($node['meta']['event'] ?? '') ?: $this->deriveEvent($command, $aggregate),
                'meta' => $node['meta'],
            ];
        }

        $stateFields = ['id' => 'string'];
        foreach ($tasks as $task) {
            foreach ($task['fields'] as $field) {
                $stateFields[$field['name']] = $field['type'];
            }
        }

        return [
            'context' => $context,
            'aggregate' => $aggregate,
            'nodes' => $nodes,
            'tasks' => $tasks,
            'incoming' => $incoming,
            'outgoing' => $outgoing,
            'stateFields' => $stateFields,
            'hasStateMachine' => $this->any($tasks, static fn (array $t): bool => $t['state'] !== null),
            'initial' => (string) ($process['initial'] ?? ''),
        ];
    }

    /**
     * Emit the ESDM core docs + state machine for one resolved process.
     *
     * @param array<string, mixed> $r resolved process
     * @param list<array<string, mixed>> $documents
     * @param list<array<string, mixed>> $stateMachines
     * @param list<string> $notes
     */
    private function emitProcess(array $r, string $domain, array &$documents, array &$stateMachines, array &$notes): void
    {
        $context = $r['context'];
        $aggregate = $r['aggregate'];
        $nodes = $r['nodes'];
        $tasks = $r['tasks'];
        $incoming = $r['incoming'];
        $outgoing = $r['outgoing'];
        $stateFields = $r['stateFields'];
        $hasStateMachine = $r['hasStateMachine'];

        $documents[] = ['apiVersion' => self::API_CORE, 'kind' => 'bounded-context', 'name' => $context, 'scope' => ['domain' => $domain]];
        $documents[] = [
            'apiVersion' => self::API_CORE,
            'kind' => 'aggregate',
            'name' => $aggregate,
            'scope' => ['domain' => $domain, 'boundedContext' => $context],
            'identifiedBy' => ['source' => 'state', 'field' => 'id'],
            'state' => $this->objectSchema($stateFields, withDefaults: true),
        ];

        // Events, then commands.
        foreach ($tasks as $task) {
            $eventFields = ['id' => 'string'];
            foreach ($task['fields'] as $field) {
                $eventFields[$field['name']] = $field['type'];
            }
            $eventDoc = [
                'apiVersion' => self::API_CORE,
                'kind' => 'event',
                'name' => $task['event'],
                'scope' => ['domain' => $domain, 'boundedContext' => $context, 'aggregate' => $aggregate],
                'data' => $this->objectSchema($eventFields, withDefaults: false),
            ];
            if ($task['state'] !== null) {
                $eventDoc['data']['properties']['status'] = ['type' => 'string', 'default' => $task['state']];
                $eventDoc['data']['required'][] = 'status';
            }
            $documents[] = $eventDoc;
        }

        foreach ($tasks as $task) {
            $commandFields = $task['isCreate'] ? [] : ['id' => 'string'];
            foreach ($task['fields'] as $field) {
                $commandFields[$field['name']] = $field['type'];
            }
            // Pin the resolved lifecycle so the generator does not re-guess it
            // from the verb (e.g. "cancel-order" would otherwise read as delete).
            $documents[] = [
                'apiVersion' => self::API_CORE,
                'kind' => 'command',
                'name' => $task['command'],
                'scope' => ['domain' => $domain, 'boundedContext' => $context, 'aggregate' => $aggregate],
                'metadata' => ['annotations' => ['esdm-extensions.io/lifecycle' => $task['lifecycle']]],
                'data' => $this->objectSchema($commandFields, withDefaults: false),
                'publishes' => [$task['event']],
            ];
        }

        // Read model + queries.
        $plural = $this->plural($aggregate);
        $rmFields = $stateFields;
        if ($hasStateMachine) {
            $rmFields['status'] = 'string';
        }
        $projections = [];
        foreach ($tasks as $task) {
            $projections[] = [
                'boundedContext' => $context,
                'aggregate' => $aggregate,
                'event' => $task['event'],
                'rule' => $this->projectionRule($task),
            ];
        }
        $documents[] = [
            'apiVersion' => self::API_CORE,
            'kind' => 'read-model',
            'name' => $plural,
            'scope' => ['domain' => $domain, 'boundedContext' => $context],
            'paradigm' => 'tabular',
            'schema' => $this->objectSchema($rmFields, withDefaults: true),
            'projections' => $projections,
        ];
        $documents[] = [
            'apiVersion' => self::API_CORE,
            'kind' => 'query',
            'name' => 'list-' . $plural,
            'scope' => ['domain' => $domain, 'boundedContext' => $context],
            'readModel' => $plural,
            'result' => ['type' => 'array', 'items' => ['type' => 'object']],
        ];
        $documents[] = [
            'apiVersion' => self::API_CORE,
            'kind' => 'query',
            'name' => 'get-' . $aggregate,
            'scope' => ['domain' => $domain, 'boundedContext' => $context],
            'readModel' => $plural,
            'parameters' => $this->objectSchema(['id' => 'string'], withDefaults: false),
            'result' => ['type' => 'object'],
        ];

        if (!$hasStateMachine) {
            return;
        }

        // [0001] state machine, derived from the flow graph.
        $states = [];
        foreach ($tasks as $task) {
            if ($task['state'] !== null) {
                $states[$task['state']] ??= false;
                if ($this->isFinalState($task, $tasks, $outgoing) || ($task['meta']['final'] ?? '') === 'true') {
                    $states[$task['state']] = true;
                }
            }
        }
        $transitions = [];
        foreach ($tasks as $task) {
            if ($task['state'] !== null) {
                $transitions[] = ['on' => $task['event'], 'to' => $task['state']];
            }
        }
        $admits = [];
        foreach ($tasks as $task) {
            if ($task['isCreate']) {
                continue;
            }
            $from = $this->admitFrom($task, $tasks, $incoming, $nodes);
            $when = $this->admitWhen($task, $incoming, $notes);
            if ($from === [] && $when === null) {
                continue;
            }
            $admit = ['command' => $task['command'], 'from' => $from];
            if ($when !== null) {
                $admit['when'] = $when;
            }
            $admits[] = $admit;
        }

        $initial = $this->slug($r['initial']) ?: $this->initialState($tasks);
        $stateMachines[] = [
            'apiVersion' => self::API_SM,
            'kind' => 'state-machine',
            'name' => $aggregate . '-lifecycle',
            'scope' => ['domain' => $domain, 'boundedContext' => $context, 'aggregate' => $aggregate],
            'initial' => $initial,
            'states' => array_map(static fn (string $n) => $states[$n] ? ['name' => $n, 'final' => true] : ['name' => $n], array_keys($states)),
            'transitions' => $transitions,
            'admits' => $admits,
            '_aggregate' => $aggregate, // consumed by the writer to name the sidecar; stripped before emit
        ];
    }

    /**
     * A cross-pool BPMN message flow → an ESDM policy: when the source task's
     * event occurs, emit the target task's command on its aggregate.
     *
     * @param array{source: string, target: string, name: string} $flow
     * @param array<string, array{context: string, aggregate: string, task: array<string, mixed>}> $index
     * @param list<array<string, mixed>> $documents
     * @param list<string> $notes
     */
    private function emitPolicy(array $flow, array $index, string $domain, array &$documents, array &$notes): void
    {
        $source = $index[$flow['source']] ?? null;
        $target = $index[$flow['target']] ?? null;
        if ($source === null || $target === null) {
            $notes[] = sprintf('message flow %s → %s not mapped (endpoints must be tasks)', $flow['source'], $flow['target']);

            return;
        }

        $event = $source['task']['event'];
        $command = $target['task']['command'];
        $documents[] = [
            'apiVersion' => self::API_CORE,
            'kind' => 'policy',
            'name' => $this->slug($flow['name']) ?: ($command . '-on-' . $event),
            'scope' => ['domain' => $domain],
            'deliveryGuarantee' => 'at-most-once',
            'handles' => [['boundedContext' => $source['context'], 'aggregate' => $source['aggregate'], 'event' => $event]],
            'emits' => [['boundedContext' => $target['context'], 'aggregate' => $target['aggregate'], 'command' => $command]],
        ];
    }

    /**
     * Source states a command is admitted from: the resulting states of the
     * task nodes that reach it through the sequence-flow graph (walking back
     * through gateways/events until a state-bearing task is hit).
     *
     * @param array<string, mixed> $task
     * @param array<string, array<string, mixed>> $tasks
     * @param array<string, list<array{from: string, condition: ?string}>> $incoming
     * @param array<string, array<string, mixed>> $nodes
     * @return list<string>
     */
    private function admitFrom(array $task, array $tasks, array $incoming, array $nodes): array
    {
        if (isset($task['meta']['from'])) {
            return $this->splitList($task['meta']['from']);
        }

        $states = [];
        $seen = [];
        $queue = array_map(static fn (array $edge): string => $edge['from'], $incoming[$task['id']] ?? []);
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (isset($tasks[$id]) && $tasks[$id]['state'] !== null) {
                $states[$tasks[$id]['state']] = true;
                continue; // stop at the first state-bearing predecessor on this branch
            }
            foreach ($incoming[$id] ?? [] as $edge) {
                $queue[] = $edge['from'];
            }
        }

        return array_keys($states);
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, list<array{from: string, condition: ?string}>> $incoming
     * @param list<string> $notes
     */
    private function admitWhen(array $task, array $incoming, array &$notes): ?string
    {
        if (isset($task['meta']['when']) && $task['meta']['when'] !== '') {
            return $this->validateFeel((string) $task['meta']['when'], $task['command'], $notes);
        }

        $conditions = [];
        foreach ($incoming[$task['id']] ?? [] as $edge) {
            if ($edge['condition'] !== null && $edge['condition'] !== '') {
                $conditions[$edge['condition']] = true;
            }
        }
        if ($conditions === []) {
            return null;
        }
        $keys = array_keys($conditions);
        $expression = count($keys) === 1 ? $keys[0] : '(' . implode(') or (', $keys) . ')';

        return $this->validateFeel($expression, $task['command'], $notes);
    }

    /** @param list<string> $notes */
    private function validateFeel(string $expression, string $command, array &$notes): string
    {
        try {
            Feel::parse($expression);
        } catch (\Throwable $e) {
            $notes[] = sprintf('guard on "%s" is not valid FEEL (%s): %s', $command, $e->getMessage(), $expression);
        }

        return $expression;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, array<string, mixed>> $tasks
     * @param array<string, list<array{to: string, condition: ?string}>> $outgoing
     */
    private function isFinalState(array $task, array $tasks, array $outgoing): bool
    {
        $seen = [];
        $queue = array_map(static fn (array $edge): string => $edge['to'], $outgoing[$task['id']] ?? []);
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (isset($tasks[$id])) {
                return false; // a downstream task exists — not terminal
            }
            foreach ($outgoing[$id] ?? [] as $edge) {
                $queue[] = $edge['to'];
            }
        }

        return true;
    }

    /** @param array<string, array<string, mixed>> $tasks */
    private function initialState(array $tasks): string
    {
        foreach ($tasks as $task) {
            if ($task['isCreate'] && $task['state'] !== null) {
                return $task['state'];
            }
        }
        foreach ($tasks as $task) {
            if ($task['state'] !== null) {
                return $task['state'];
            }
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $flows
     * @return array{0: array<string, list<array{from: string, condition: ?string}>>, 1: array<string, list<array{to: string, condition: ?string}>>}
     */
    private function adjacency(array $flows): array
    {
        $incoming = [];
        $outgoing = [];
        foreach ($flows as $flow) {
            $outgoing[$flow['source']][] = ['to' => $flow['target'], 'condition' => $flow['condition']];
            $incoming[$flow['target']][] = ['from' => $flow['source'], 'condition' => $flow['condition']];
        }

        return [$incoming, $outgoing];
    }

    /** @param array<string, mixed> $task */
    private function projectionRule(array $task): string
    {
        return match ($task['lifecycle']) {
            Lifecycle::Create->value => 'Insert a row.',
            Lifecycle::Delete->value => 'Delete the row.',
            default => $task['state'] !== null ? 'Update the row and set status to ' . $task['state'] . '.' : 'Update the row.',
        };
    }

    private function deriveEvent(string $command, string $aggregate): string
    {
        $parts = explode('-', $command);
        $verb = array_shift($parts) ?? $command;
        $object = implode('-', $parts);
        if ($object === '') {
            $object = $aggregate;
        }

        return $object . '-' . $this->pastParticiple($verb);
    }

    private function pastParticiple(string $verb): string
    {
        if (isset(self::PAST[$verb])) {
            return self::PAST[$verb];
        }

        return str_ends_with($verb, 'e') ? $verb . 'd' : $verb . 'ed';
    }

    /**
     * @param array<string, string> $fields name => json type
     * @return array<string, mixed>
     */
    private function objectSchema(array $fields, bool $withDefaults): array
    {
        $properties = [];
        foreach ($fields as $name => $type) {
            $definition = ['type' => $type];
            if ($withDefaults && $name !== 'id') {
                $definition['default'] = $this->defaultFor($type);
            }
            $properties[$name] = $definition;
        }

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($fields)];
    }

    private function defaultFor(string $type): mixed
    {
        return match ($type) {
            'number', 'integer' => 0,
            'boolean' => false,
            default => '',
        };
    }

    /** @return list<string> */
    private function splitList(string $raw): array
    {
        $parts = preg_split('/[\s,]+/', trim($raw)) ?: [];

        return array_values(array_filter(array_map([$this, 'slug'], $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @param array<string, array<string, mixed>> $items
     * @param callable(array<string, mixed>): bool $predicate
     */
    private function any(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }

    private function slug(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';

        return trim($value, '-');
    }

    private function singular(string $value): string
    {
        if (str_ends_with($value, 'ies') && strlen($value) > 4) {
            return substr($value, 0, -3) . 'y';
        }
        if (str_ends_with($value, 'ses') && strlen($value) > 4) {
            return substr($value, 0, -2);
        }
        if (str_ends_with($value, 's') && !str_ends_with($value, 'ss') && strlen($value) > 3) {
            return substr($value, 0, -1);
        }

        return $value;
    }

    private function plural(string $value): string
    {
        if (str_ends_with($value, 'y') && !preg_match('/[aeiou]y$/', $value)) {
            return substr($value, 0, -1) . 'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/', $value) === 1) {
            return $value . 'es';
        }

        return $value . 's';
    }
}
