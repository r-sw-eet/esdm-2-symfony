<?php

declare(strict_types=1);

namespace Esdm\Generator\Adapter\SymfonyEventSourcingDb;

use Esdm\Generator\Adapter\Adapter;
use Esdm\Generator\Adapter\GeneratedProject;
use Esdm\Generator\Feel\Feel;
use Esdm\Generator\Model\Aggregate;
use Esdm\Generator\Model\BoundedContext;
use Esdm\Generator\Model\Command;
use Esdm\Generator\Model\Event;
use Esdm\Generator\Model\EventExample;
use Esdm\Generator\Model\Field;
use Esdm\Generator\Model\Lifecycle;
use Esdm\Generator\Model\Model;
use Esdm\Generator\Model\Query;
use Esdm\Generator\Model\ReadModel;
use Esdm\Generator\Model\Scenario;
use Esdm\Generator\Support\Str;

/**
 * Emits a runnable, dockerized Symfony application that implements the ESDM
 * model with CQRS + event sourcing on top of EventSourcingDB and MongoDB.
 * Write side: commands -> pure decider -> events appended to EventSourcingDB
 * (subject `/<aggregate>/<id>`, nimbus-compatible data envelope). Read side: a
 * long-running worker observes the stream and projects events into MongoDB
 * read collections (`rm_*`) that the query API reads.
 */
final class SymfonyEventSourcingDbAdapter implements Adapter
{
    public function name(): string
    {
        return 'symfony-eventsourcingdb';
    }

    public function description(): string
    {
        return 'Symfony 7 + EventSourcingDB + MongoDB read models (CQRS, event-sourced, streamed projections).';
    }

    public function slug(): string
    {
        return 'symfony-esdb';
    }

    public function generate(Model $model, array $options): GeneratedProject
    {
        $namespace = (string) ($options['namespace'] ?? 'App');
        $appName = (string) ($options['appName'] ?? $model->domain);
        $source = (string) ($options['source'] ?? 'https://esdm-extensions.io/' . $model->domain);

        $project = new GeneratedProject();

        foreach ($model->boundedContexts as $context) {
            foreach ($context->aggregates as $aggregate) {
                $this->emitEventTypes($project, $namespace, $context, $aggregate);
                $this->emitState($project, $namespace, $context, $aggregate);
                $this->emitDecider($project, $namespace, $context, $aggregate);
                $this->emitCommands($project, $namespace, $context, $aggregate);
                $this->emitApplicationService($project, $namespace, $context, $aggregate);
            }
            foreach ($context->readModels as $readModel) {
                $this->emitProjector($project, $namespace, $context, $readModel);
                $this->emitFinder($project, $namespace, $context, $readModel);
            }
            if ($context->aggregates !== [] || $context->readModels !== []) {
                $this->emitController($project, $namespace, $context);
            }
        }

        $this->emitPolicies($project, $namespace, $model);
        $this->emitObserveWorker($project, $namespace, $model);
        $this->emitTests($project, $namespace, $model);
        $this->emitDevEndpoint($project, $namespace, $model, $options);
        $this->emitSkeleton($project, $namespace, $appName, $model, $source);

        return $project;
    }

    // ---- Domain: event-type constants ---------------------------------------

    private function emitEventTypes(GeneratedProject $project, string $ns, BoundedContext $context, Aggregate $aggregate): void
    {
        $ctx = Str::studly($context->name);
        $class = Str::studly($aggregate->name) . 'Events';

        $lines = [
            '/** Event-type constants of the `' . $aggregate->name . '` aggregate (CloudEvents `type`). */',
            'final class ' . $class,
            '{',
        ];
        foreach ($aggregate->events as $event) {
            $lines[] = '    public const ' . $this->eventConst($event) . ' = ' . $this->q($event->type) . ';';
        }
        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/Domain/Event/' . $class . '.php',
            $this->phpFile($ns . '\\' . $ctx . '\\Domain\\Event', [], $lines),
        );
    }

    // ---- Domain: state fold --------------------------------------------------

    private function emitState(GeneratedProject $project, string $ns, BoundedContext $context, Aggregate $aggregate): void
    {
        $ctx = Str::studly($context->name);
        $class = Str::studly($aggregate->name) . 'State';
        $eventsClass = Str::studly($aggregate->name) . 'Events';
        $idProp = Str::camel($aggregate->identityField);

        $lines = [
            '/**',
            ' * Pure state fold of the `' . $aggregate->name . '` aggregate: replaying a subject through',
            ' * apply() rebuilds current state. No store knowledge lives here.',
            ' */',
            'final class ' . $class,
            '{',
        ];
        foreach ($aggregate->nonIdentityState() as $field) {
            $lines[] = '    public ' . Types::nullablePhpType($field) . ' $' . Str::camel($field->name) . ' = null;';
        }
        if ($aggregate->stateMachine !== null) {
            $lines[] = '    public ?string $status = null;';
        }
        $lines[] = '';
        $lines[] = '    public function __construct(public readonly string $' . $idProp . ')';
        $lines[] = '    {';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    /** @param array<string, mixed> $data */';
        $lines[] = '    public function apply(string $type, array $data): void';
        $lines[] = '    {';
        $lines[] = '        switch ($type) {';
        foreach ($aggregate->events as $event) {
            $lines[] = '            case ' . $eventsClass . '::' . $this->eventConst($event) . ':';
            foreach ($this->stateAssignments($aggregate, $event) as $assign) {
                $lines[] = '                ' . $assign;
            }
            $lines[] = '                return;';
        }
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/Domain/' . $class . '.php',
            $this->phpFile($ns . '\\' . $ctx . '\\Domain', [$ns . '\\' . $ctx . '\\Domain\\Event\\' . $eventsClass], $lines),
        );
    }

    /** The `$this->x = ...;` fold assignments for one event. */
    private function stateAssignments(Aggregate $aggregate, Event $event): array
    {
        $out = [];
        if ($event->lifecycle === Lifecycle::Create) {
            foreach ($aggregate->nonIdentityState() as $field) {
                $prop = Str::camel($field->name);
                $out[] = $event->data->has($field->name)
                    ? '$this->' . $prop . ' = ' . Types::payloadCast($field, '$data[' . $this->q($prop) . ']') . ';'
                    : '$this->' . $prop . ' = ' . Types::defaultLiteral($field) . ';';
            }
        } elseif ($event->lifecycle !== Lifecycle::Delete) {
            foreach ($event->data as $field) {
                if ($field->name === $aggregate->identityField || !$aggregate->state->has($field->name)) {
                    continue;
                }
                $prop = Str::camel($field->name);
                $out[] = '$this->' . $prop . ' = ' . Types::payloadCast($field, '$data[' . $this->q($prop) . ']') . ';';
            }
        }

        if ($aggregate->stateMachine !== null) {
            $target = $aggregate->stateMachine->transitionTarget($event->name);
            if ($target === null && $event->lifecycle === Lifecycle::Create) {
                $target = $aggregate->stateMachine->initial;
            }
            if ($target !== null) {
                $out[] = '$this->status = ' . $this->q($target) . ';';
            }
        }

        return $out;
    }

    // ---- Domain: pure decider -------------------------------------------------

    private function emitDecider(GeneratedProject $project, string $ns, BoundedContext $context, Aggregate $aggregate): void
    {
        $ctx = Str::studly($context->name);
        $class = Str::studly($aggregate->name);
        $eventsClass = $class . 'Events';
        $domainNs = $ns . '\\' . $ctx . '\\Domain';

        $uses = [
            $ns . '\\Shared\\EventStore\\DomainEvent',
            $domainNs . '\\Event\\' . $eventsClass,
        ];
        foreach ($aggregate->commands as $command) {
            $uses[] = $domainNs . '\\Command\\' . Str::studly($command->name);
        }
        if ($aggregate->stateMachine !== null) {
            $uses[] = $ns . '\\Shared\\IllegalTransition';
            foreach ($aggregate->commands as $command) {
                if ($this->commandGuard($aggregate, $command)['feelPhp'] !== null) {
                    $uses[] = $ns . '\\Shared\\GuardViolation';
                    break;
                }
            }
        }

        $lines = [
            '/**',
            ' * Pure decider of the `' . $aggregate->name . '` aggregate: state + command in, events out.',
            ' * Guards (state machine 0001, FEEL 0002) throw domain violations mapped to 409.',
            ' */',
            'final class ' . $class,
            '{',
        ];

        $first = true;
        foreach ($aggregate->commands as $command) {
            $event = $aggregate->event((string) $command->primaryEvent());
            if ($event === null) {
                continue;
            }
            if (!$first) {
                $lines[] = '';
            }
            $first = false;
            $lines = array_merge($lines, $this->deciderMethod($aggregate, $command, $event, $class, $eventsClass));
        }

        $lines[] = '}';

        $project->add('src/' . $ctx . '/Domain/' . $class . '.php', $this->phpFile($domainNs, $uses, $lines));
    }

    /** @return list<string> */
    private function deciderMethod(Aggregate $aggregate, Command $command, Event $event, string $stateClassBase, string $eventsClass): array
    {
        $method = Str::camel($command->name);
        $cmdClass = Str::studly($command->name);
        $stateClass = $stateClassBase . 'State';
        $guard = $command->lifecycle === Lifecycle::Create
            ? ['state' => [], 'feelPhp' => null, 'feelText' => null, 'usesToday' => false, 'usesNow' => false]
            : $this->commandGuard($aggregate, $command);

        $params = [$stateClass . ' $state', $cmdClass . ' $command'];
        foreach ($this->clockParams($guard) as $param) {
            $params[] = $param;
        }

        $lines = [
            '    /** @return list<DomainEvent> */',
            '    public static function ' . $method . '(' . implode(', ', $params) . '): array',
            '    {',
        ];
        $lines = array_merge($lines, $this->guardStatements($command, $guard));
        $lines[] = '        return [new DomainEvent(' . $eventsClass . '::' . $this->eventConst($event) . ', [';
        foreach ($this->eventData($aggregate, $command, $event) as $entry) {
            $lines[] = '            ' . $entry;
        }
        $lines[] = '        ])];';
        $lines[] = '    }';

        return $lines;
    }

    /** The `'key' => value,` entries of the emitted event's payload (camelCase keys). */
    private function eventData(Aggregate $aggregate, Command $command, Event $event): array
    {
        $out = [];
        $create = $command->lifecycle === Lifecycle::Create;
        $idProp = Str::camel($aggregate->identityField);
        foreach ($event->data as $field) {
            $key = $this->q(Str::camel($field->name));
            $camel = Str::camel($field->name);
            if ($field->name === $aggregate->identityField) {
                $out[] = $key . ' => $state->' . $idProp . ',';
            } elseif ($command->data->has($field->name)) {
                $out[] = $key . ' => $command->' . $camel . ',';
            } elseif (!$create && $aggregate->state->has($field->name)) {
                $out[] = $key . ' => $state->' . $camel . ' ?? ' . Types::defaultLiteral($field) . ',';
            } else {
                $out[] = $key . ' => ' . Types::defaultLiteral($field) . ',';
            }
        }

        return $out;
    }

    /**
     * A command's precondition: the states it is admissible from (0001) and an
     * optional compiled FEEL predicate (0002), bound to `$state->...`.
     *
     * @return array{state: list<string>, feelPhp: ?string, feelText: ?string, usesToday: bool, usesNow: bool}
     */
    private function commandGuard(Aggregate $aggregate, Command $command): array
    {
        $result = ['state' => [], 'feelPhp' => null, 'feelText' => null, 'usesToday' => false, 'usesNow' => false];
        if ($aggregate->stateMachine === null) {
            return $result;
        }
        $admit = $aggregate->stateMachine->admitFor($command->name);
        if ($admit === null) {
            return $result;
        }

        $result['state'] = $admit->from;
        if ($admit->when !== null && $admit->when !== '') {
            $compiled = Feel::compile(Feel::parse($admit->when), static fn (string $n): string => '$state->' . Str::camel($n));
            $result['feelPhp'] = $compiled['php'];
            $result['feelText'] = $admit->when;
            $result['usesToday'] = $compiled['usesToday'];
            $result['usesNow'] = $compiled['usesNow'];
        }

        return $result;
    }

    /**
     * @param array{usesToday: bool, usesNow: bool} $guard
     * @return list<string>
     */
    private function clockParams(array $guard): array
    {
        $params = [];
        if ($guard['usesToday']) {
            $params[] = 'string $today';
        }
        if ($guard['usesNow']) {
            $params[] = 'string $now';
        }

        return $params;
    }

    /**
     * @param array{state: list<string>, feelPhp: ?string, feelText: ?string} $guard
     * @return list<string>
     */
    private function guardStatements(Command $command, array $guard): array
    {
        $lines = [];
        if ($guard['state'] !== []) {
            $list = implode(', ', array_map(fn (string $s): string => $this->q($s), $guard['state']));
            $lines[] = '        if (!in_array($state->status, [' . $list . '], true)) {';
            $lines[] = '            throw new IllegalTransition(' . $this->q($command->name) . ', ($state->status ?? \'\') === \'\' ? \'undefined\' : (string) $state->status);';
            $lines[] = '        }';
            $lines[] = '';
        }
        if ($guard['feelPhp'] !== null) {
            $lines[] = '        if (!(' . $guard['feelPhp'] . ')) {';
            $lines[] = '            throw new GuardViolation(' . $this->q($command->name) . ', ' . $this->q((string) $guard['feelText']) . ');';
            $lines[] = '        }';
            $lines[] = '';
        }

        return $lines;
    }

    // ---- Domain: command DTOs -------------------------------------------------

    private function emitCommands(GeneratedProject $project, string $ns, BoundedContext $context, Aggregate $aggregate): void
    {
        $ctx = Str::studly($context->name);
        $commandNs = $ns . '\\' . $ctx . '\\Domain\\Command';

        foreach ($aggregate->commands as $command) {
            $class = Str::studly($command->name);
            $params = [];
            foreach ($command->data as $field) {
                $params[] = '        public readonly ' . Types::scalarPhpType($field) . ' $' . Str::camel($field->name) . ',';
            }

            $lines = [
                'final class ' . $class,
                '{',
                '    public function __construct(',
            ];
            $lines = array_merge($lines, $params);
            $lines[] = '    ) {';
            $lines[] = '    }';
            $lines[] = '}';

            $project->add(
                'src/' . $ctx . '/Domain/Command/' . $class . '.php',
                $this->phpFile($commandNs, [], $lines),
            );
        }
    }

    // ---- Application service ---------------------------------------------------

    private function emitApplicationService(GeneratedProject $project, string $ns, BoundedContext $context, Aggregate $aggregate): void
    {
        $ctx = Str::studly($context->name);
        $appNs = $ns . '\\' . $ctx . '\\Application';
        $domainNs = $ns . '\\' . $ctx . '\\Domain';
        $aggClass = Str::studly($aggregate->name);
        $stateClass = $aggClass . 'State';
        $idProp = Str::camel($aggregate->identityField);

        $uses = [
            $domainNs . '\\' . $aggClass,
            $domainNs . '\\' . $stateClass,
            $ns . '\\Shared\\EventStore\\EventStore',
        ];
        $mints = false;
        foreach ($aggregate->commands as $command) {
            $uses[] = $domainNs . '\\Command\\' . Str::studly($command->name);
            if ($command->lifecycle === Lifecycle::Create) {
                $mints = true;
            }
        }
        if ($mints) {
            $uses[] = 'Symfony\\Component\\Uid\\Uuid';
        }

        $lines = [
            'final class ' . $aggClass . 'Service',
            '{',
            '    private const SUBJECT_ROOT = ' . $this->q($this->subjectRoot($aggregate)) . ';',
            '',
            '    public function __construct(private readonly EventStore $eventStore)',
            '    {',
            '    }',
        ];

        foreach ($aggregate->commands as $command) {
            $lines[] = '';
            $lines = array_merge($lines, $this->serviceMethod($aggregate, $command, $aggClass, $stateClass, $idProp));
        }

        $lines[] = '';
        $lines[] = '    private function replay(string $id): ' . $stateClass;
        $lines[] = '    {';
        $lines[] = '        $state = new ' . $stateClass . '($id);';
        $lines[] = '        foreach ($this->eventStore->read(self::SUBJECT_ROOT . \'/\' . $id) as $event) {';
        $lines[] = '            $state->apply($event->type, $event->payload);';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        return $state;';
        $lines[] = '    }';
        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/Application/' . $aggClass . 'Service.php',
            $this->phpFile($appNs, $uses, $lines),
        );
    }

    /** @return list<string> */
    private function serviceMethod(Aggregate $aggregate, Command $command, string $aggClass, string $stateClass, string $idProp): array
    {
        $method = Str::camel($command->name);
        $cmdClass = Str::studly($command->name);

        if ($command->lifecycle === Lifecycle::Create) {
            // A caller-assigned identity makes the create idempotent (a re-issued id
            // hits the pristine-subject precondition); an empty one falls back to minting.
            $mint = $command->data->has($aggregate->identityField)
                ? '$command->' . $idProp . " === '' ? Uuid::v7()->toRfc4122() : \$command->" . $idProp
                : 'Uuid::v7()->toRfc4122()';

            return [
                '    public function ' . $method . '(' . $cmdClass . ' $command, string $correlationId): string',
                '    {',
                '        $id = ' . $mint . ';',
                '        $events = ' . $aggClass . '::' . $method . '(new ' . $stateClass . '($id), $command);',
                '        $this->eventStore->append(self::SUBJECT_ROOT . \'/\' . $id, $events, $correlationId, EventStore::PRISTINE);',
                '',
                '        return $id;',
                '    }',
            ];
        }

        // A guard using today()/now() needs the current date/time passed in.
        $guard = $this->commandGuard($aggregate, $command);
        $callArgs = ['$state', '$command'];
        if ($guard['usesToday']) {
            $callArgs[] = "(new \\DateTimeImmutable())->format('Y-m-d')";
        }
        if ($guard['usesNow']) {
            $callArgs[] = "(new \\DateTimeImmutable())->format('Y-m-d\\TH:i:sP')";
        }

        return [
            '    public function ' . $method . '(' . $cmdClass . ' $command, string $correlationId): void',
            '    {',
            '        $state = $this->replay($command->' . $idProp . ');',
            '        $events = ' . $aggClass . '::' . $method . '(' . implode(', ', $callArgs) . ');',
            '        $this->eventStore->append(self::SUBJECT_ROOT . \'/\' . $command->' . $idProp . ', $events, $correlationId, EventStore::POPULATED);',
            '    }',
        ];
    }

    // ---- Read model: projector ---------------------------------------------------

    private function emitProjector(GeneratedProject $project, string $ns, BoundedContext $context, ReadModel $readModel): void
    {
        $ctx = Str::studly($context->name);
        $rmNs = $ns . '\\' . $ctx . '\\ReadModel';
        $class = Str::studly($readModel->name) . 'Projector';
        $collection = 'rm_' . Str::snake($readModel->name);
        $pk = $this->primaryKeyColumn($readModel);
        $events = $this->projectedEvents($context, $readModel);
        $anchor = $this->anchorEvent($events);

        $uses = [
            $ns . '\\Shared\\EventStore\\EventConsumer',
            $ns . '\\Shared\\EventStore\\StoredEvent',
            'MongoDB\\Collection',
            'MongoDB\\Database',
        ];
        foreach ($this->eventsClassesOf($ns, $context, $events) as $use) {
            $uses[] = $use;
        }

        $lines = [
            '/**',
            ' * Projects events into the `' . $collection . '` MongoDB collection. Runs in the',
            ' * app:observe worker; `revision` tracks the last projected event id per row.',
            ' */',
            'final class ' . $class . ' implements EventConsumer',
            '{',
            '    private const COLLECTION = ' . $this->q($collection) . ';',
            '',
            '    public function __construct(private readonly Database $database)',
            '    {',
            '    }',
            '',
            '    public function subjectRoot(): string',
            '    {',
            '        return ' . $this->q($this->projectionSubject($context, $readModel)) . ';',
            '    }',
            '',
            '    public function setup(): void',
            '    {',
            '        $this->collection()->createIndex([' . $this->q($pk) . ' => 1], [\'unique\' => true]);',
            '    }',
            '',
            '    /** The highest event id already projected, or null to replay from the start. */',
            '    public function lowerBound(): ?int',
            '    {',
            '        $doc = $this->collection()->findOne([], [\'sort\' => [\'revision\' => -1], \'projection\' => [\'revision\' => 1]]);',
            '',
            '        return $doc === null ? null : (int) $doc[\'revision\'];',
            '    }',
            '',
            '    public function handle(StoredEvent $event): void',
            '    {',
            '        switch ($event->type) {',
        ];

        foreach ($events as $event) {
            $aggregate = $this->aggregateOf($context, $event->aggregate);
            $eventsClass = Str::studly($event->aggregate) . 'Events';
            $lines[] = '            case ' . $eventsClass . '::' . $this->eventConst($event) . ':';
            $lines = array_merge($lines, $this->projectionBranch($readModel, $event, $aggregate, $pk, $event === $anchor));
            $lines[] = '                return;';
        }

        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function collection(): Collection';
        $lines[] = '    {';
        $lines[] = '        return $this->database->selectCollection(self::COLLECTION);';
        $lines[] = '    }';
        $lines[] = '}';

        $project->add('src/' . $ctx . '/ReadModel/' . $class . '.php', $this->phpFile($rmNs, $uses, $lines));
    }

    /** @return list<string> */
    private function projectionBranch(ReadModel $readModel, Event $event, ?Aggregate $aggregate, string $pk, bool $isAnchor): array
    {
        $idField = $aggregate?->identityField ?? $pk;
        $idKey = '$event->payload[' . $this->q(Str::camel($idField)) . ']';
        $idExpr = '(string) (' . $idKey . " ?? '')";

        if (!$isAnchor && $event->lifecycle === Lifecycle::Delete) {
            return ['                $this->collection()->deleteOne([' . $this->q($pk) . ' => ' . $idExpr . ']);'];
        }

        if ($isAnchor) {
            $lines = [
                '                $this->collection()->insertOne([',
                '                    \'revision\' => (int) $event->id,',
            ];
            foreach ($readModel->columns as $column) {
                $col = $this->q(Str::snake($column->name));
                if ($column->name === $idField) {
                    $lines[] = '                    ' . $col . ' => ' . $idExpr . ',';
                } elseif ($event->data->has($column->name)) {
                    $lines[] = '                    ' . $col . ' => ' . Types::payloadCast($column, '$event->payload[' . $this->q(Str::camel($column->name)) . ']') . ',';
                } else {
                    $default = $column->required || $column->hasDefault ? Types::defaultLiteral($column) : 'null';
                    $lines[] = '                    ' . $col . ' => ' . $default . ',';
                }
            }
            $lines[] = '                ]);';

            return $lines;
        }

        // mutate: update the columns the event carries (besides identity)
        $lines = [
            '                $this->collection()->updateOne([' . $this->q($pk) . ' => ' . $idExpr . '], [\'$set\' => [',
            '                    \'revision\' => (int) $event->id,',
        ];
        foreach ($event->data as $field) {
            if ($field->name === $idField || !$readModel->columns->has($field->name)) {
                continue;
            }
            $col = $this->q(Str::snake($field->name));
            $lines[] = '                    ' . $col . ' => ' . Types::payloadCast($field, '$event->payload[' . $this->q(Str::camel($field->name)) . ']') . ',';
        }
        $lines[] = '                ]]);';

        return $lines;
    }

    // ---- Read model: finder (query side) ------------------------------------------

    private function emitFinder(GeneratedProject $project, string $ns, BoundedContext $context, ReadModel $readModel): void
    {
        $ctx = Str::studly($context->name);
        $rmNs = $ns . '\\' . $ctx . '\\ReadModel';
        $class = Str::studly($readModel->name) . 'Finder';
        $collection = 'rm_' . Str::snake($readModel->name);
        $pk = $this->primaryKeyColumn($readModel);

        $hydrate = [];
        foreach ($readModel->columns as $column) {
            $col = Str::snake($column->name);
            $expr = '$doc[' . $this->q($col) . ']';
            $value = $column->required || $column->hasDefault || $column->isIdentity
                ? Types::payloadCast($column, $expr)
                : $expr . ' ?? null';
            $hydrate[] = '            ' . $this->q($col) . ' => ' . $value . ',';
        }

        $lines = [
            'final class ' . $class,
            '{',
            '    private const COLLECTION = ' . $this->q($collection) . ';',
            '',
            '    public function __construct(private readonly Database $database)',
            '    {',
            '    }',
            '',
            '    /** @return list<array<string, mixed>> */',
            '    public function all(): array',
            '    {',
            '        $rows = [];',
            '        foreach ($this->collection()->find([], [\'sort\' => [' . $this->q($pk) . ' => 1]]) as $doc) {',
            '            $rows[] = $this->hydrate((array) $doc);',
            '        }',
            '',
            '        return $rows;',
            '    }',
            '',
            '    /** @return array<string, mixed>|null */',
            '    public function find(string $id): ?array',
            '    {',
            '        $doc = $this->collection()->findOne([' . $this->q($pk) . ' => $id]);',
            '',
            '        return $doc === null ? null : $this->hydrate((array) $doc);',
            '    }',
            '',
            '    /**',
            '     * @param array<string, mixed> $doc',
            '     * @return array<string, mixed>',
            '     */',
            '    private function hydrate(array $doc): array',
            '    {',
            '        // _id is Mongo bookkeeping, revision the store event id - neither is part of the row.',
            '        return [',
        ];
        $lines = array_merge($lines, $hydrate);
        $lines[] = '        ];';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function collection(): Collection';
        $lines[] = '    {';
        $lines[] = '        return $this->database->selectCollection(self::COLLECTION);';
        $lines[] = '    }';
        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/ReadModel/' . $class . '.php',
            $this->phpFile($rmNs, ['MongoDB\\Collection', 'MongoDB\\Database'], $lines),
        );
    }

    // ---- API controller -------------------------------------------------------------

    private function emitController(GeneratedProject $project, string $ns, BoundedContext $context): void
    {
        $ctx = Str::studly($context->name);
        $apiNs = $ns . '\\' . $ctx . '\\Api';
        $base = '/' . $context->name;

        $uses = [
            $ns . '\\Shared\\DomainViolation',
            'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
            'Symfony\\Component\\HttpFoundation\\JsonResponse',
            'Symfony\\Component\\HttpFoundation\\Request',
            'Symfony\\Component\\HttpFoundation\\Response',
            'Symfony\\Component\\Routing\\Attribute\\Route',
            'Symfony\\Component\\Uid\\Uuid',
        ];

        $ctorArgs = [];
        $body = [];

        foreach ($context->aggregates as $aggregate) {
            $aggClass = Str::studly($aggregate->name);
            $serviceProp = Str::camel($aggregate->name) . 'Service';
            $uses[] = $ns . '\\' . $ctx . '\\Application\\' . $aggClass . 'Service';
            $ctorArgs[] = '        private readonly ' . $aggClass . 'Service $' . $serviceProp . ',';

            foreach ($aggregate->commands as $command) {
                $uses[] = $ns . '\\' . $ctx . '\\Domain\\Command\\' . Str::studly($command->name);
                $body[] = '';
                $body = array_merge($body, $this->commandAction($context, $command, $serviceProp, $base));
            }
        }

        foreach ($context->readModels as $readModel) {
            $finderProp = Str::camel($readModel->name) . 'Finder';
            $uses[] = $ns . '\\' . $ctx . '\\ReadModel\\' . Str::studly($readModel->name) . 'Finder';
            $ctorArgs[] = '        private readonly ' . Str::studly($readModel->name) . 'Finder $' . $finderProp . ',';
        }

        foreach ($context->queries as $query) {
            $readModel = $context->readModel($query->readModel);
            if ($readModel === null) {
                continue;
            }
            $finderProp = Str::camel($readModel->name) . 'Finder';
            $body[] = '';
            $body = array_merge($body, $this->queryAction($context, $query, $finderProp, $base));
        }

        $uses = array_values(array_unique($uses));

        $lines = [
            'final class ' . $ctx . 'Controller extends AbstractController',
            '{',
            '    public function __construct(',
        ];
        $lines = array_merge($lines, $ctorArgs);
        $lines[] = '    ) {';
        $lines[] = '    }';
        $lines = array_merge($lines, $body);
        $lines[] = '';
        $lines[] = '    /** @return array<string, mixed> */';
        $lines[] = '    private function payload(Request $request): array';
        $lines[] = '    {';
        $lines[] = '        return json_decode($request->getContent() ?: \'{}\', true) ?: [];';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function correlationId(): string';
        $lines[] = '    {';
        $lines[] = '        return Uuid::v4()->toRfc4122();';
        $lines[] = '    }';
        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/Api/' . $ctx . 'Controller.php',
            $this->phpFile($apiNs, $uses, $lines),
        );
    }

    /** @return list<string> */
    private function commandAction(BoundedContext $context, Command $command, string $serviceProp, string $base): array
    {
        $method = Str::camel($command->name);
        $action = Str::controllerAction($command->name);
        $cmdClass = Str::studly($command->name);
        $routeName = $context->name . '_' . Str::snake($command->name);
        $path = $base . '/' . $command->name;

        $args = [];
        foreach ($command->data as $field) {
            $args[] = $this->castFromPayload($field);
        }
        $argList = implode(', ', $args);

        $lines = [
            '    #[Route(' . $this->q($path) . ', name: ' . $this->q($routeName) . ', methods: [\'POST\'])]',
            '    public function ' . $action . '(Request $request): JsonResponse',
            '    {',
            '        $data = $this->payload($request);',
        ];

        if ($command->lifecycle === Lifecycle::Create) {
            $call = '$id = $this->' . $serviceProp . '->' . $method . '(new ' . $cmdClass . '(' . $argList . '), $this->correlationId());';
            $success = 'return new JsonResponse([\'id\' => $id]);';
        } else {
            $call = '$this->' . $serviceProp . '->' . $method . '(new ' . $cmdClass . '(' . $argList . '), $this->correlationId());';
            $success = 'return new JsonResponse([\'id\' => (string) ($data[\'id\'] ?? \'\')]);';
        }

        $lines[] = '        try {';
        $lines[] = '            ' . $call;
        $lines[] = '';
        $lines[] = '            ' . $success;
        $lines[] = '        } catch (DomainViolation $e) {';
        $lines[] = '            return new JsonResponse([\'error\' => \'CONFLICT\', \'message\' => $e->getMessage(), \'details\' => $e->details()], Response::HTTP_CONFLICT);';
        $lines[] = '        }';
        $lines[] = '    }';

        return $lines;
    }

    /** @return list<string> */
    private function queryAction(BoundedContext $context, Query $query, string $finderProp, string $base): array
    {
        $method = Str::controllerAction($query->name);
        $routeName = $context->name . '_' . Str::snake($query->name);
        $path = $base . '/' . $query->name;

        $entity = Str::studly($query->readModel);
        if ($query->parameters->fields !== []) {
            return [
                '    #[Route(' . $this->q($path) . ', name: ' . $this->q($routeName) . ', methods: [\'GET\'])]',
                '    public function ' . $method . '(Request $request): JsonResponse',
                '    {',
                '        $row = $this->' . $finderProp . '->find((string) $request->query->get(\'id\', \'\'));',
                '',
                '        return $row === null',
                '            ? new JsonResponse([\'error\' => \'NOT_FOUND\', \'message\' => ' . $this->q($entity . ' not found') . ', \'details\' => [\'errorCode\' => ' . $this->q(strtoupper(Str::snake($query->readModel)) . '_NOT_FOUND') . ', \'reason\' => ' . $this->q('Could not find ' . $entity . ' matching the given filter') . ']], Response::HTTP_NOT_FOUND)',
                '            : new JsonResponse($row);',
                '    }',
            ];
        }

        return [
            '    #[Route(' . $this->q($path) . ', name: ' . $this->q($routeName) . ', methods: [\'GET\'])]',
            '    public function ' . $method . '(): JsonResponse',
            '    {',
            '        return new JsonResponse($this->' . $finderProp . '->all());',
            '    }',
        ];
    }

    private function castFromPayload(Field $field): string
    {
        $key = $this->q($field->name);
        $fallback = Types::defaultLiteral($field);

        return match ($field->jsonType) {
            'string' => '(string) ($data[' . $key . '] ?? ' . $fallback . ')',
            'boolean' => '(bool) ($data[' . $key . '] ?? ' . $fallback . ')',
            'integer' => '(int) ($data[' . $key . '] ?? ' . $fallback . ')',
            'number' => '(float) ($data[' . $key . '] ?? ' . $fallback . ')',
            default => '$data[' . $key . '] ?? null',
        };
    }

    // ---- Policy -> stream consumer (event reacts, dispatches a command) -----------

    private function emitPolicies(GeneratedProject $project, string $ns, Model $model): void
    {
        foreach ($model->policies as $policy) {
            $handleAgg = $model->aggregate($policy->handleContext, $policy->handleAggregate);
            $emitAgg = $model->aggregate($policy->emitContext, $policy->emitAggregate);
            if ($handleAgg === null || $emitAgg === null) {
                continue;
            }
            $event = $handleAgg->event($policy->handleEvent);
            $command = $this->commandOf($emitAgg, $policy->emitCommand);
            if ($event === null || $command === null) {
                continue;
            }

            $handleCtx = Str::studly($policy->handleContext);
            $emitCtx = Str::studly($policy->emitContext);
            $policyNs = $ns . '\\' . $emitCtx . '\\Policy';
            $class = Str::studly($policy->name) . 'Policy';
            $handleEventsClass = Str::studly($handleAgg->name) . 'Events';
            $cmdClass = Str::studly($command->name);
            $aggClass = Str::studly($emitAgg->name);
            $serviceProp = Str::camel($emitAgg->name) . 'Service';
            $method = Str::camel($command->name);
            $handleIdKey = '$event->payload[' . $this->q(Str::camel($handleAgg->identityField)) . ']';
            $targetIdName = Str::camel($policy->handleAggregate) . 'Id'; // e.g. requestId

            // Map the emitted command's fields from the trigger event: the trigger
            // aggregate's identity is exposed as <aggregate>Id, the rest by name.
            $args = [];
            foreach ($command->data as $field) {
                if ($field->name === $targetIdName) {
                    $args[] = '(string) (' . $handleIdKey . " ?? '')";
                } elseif ($event->data->has($field->name)) {
                    $args[] = Types::payloadCast($field, '$event->payload[' . $this->q(Str::camel($field->name)) . ']');
                } else {
                    $args[] = Types::defaultLiteral($field);
                }
            }

            $uses = [
                $ns . '\\' . $handleCtx . '\\Domain\\Event\\' . $handleEventsClass,
                $ns . '\\' . $emitCtx . '\\Application\\' . $aggClass . 'Service',
                $ns . '\\' . $emitCtx . '\\Domain\\Command\\' . $cmdClass,
                $ns . '\\Shared\\EventStore\\EventConsumer',
                $ns . '\\Shared\\EventStore\\StoredEvent',
            ];

            $lines = [
                '/**',
                ' * Policy `' . $policy->name . '`: reacts to ' . $policy->handleEvent . ' by dispatching',
                ' * ' . $policy->emitCommand . ' through the application service (at-most-once, no cursor).',
                ' */',
                'final class ' . $class . ' implements EventConsumer',
                '{',
                '    public function __construct(private readonly ' . $aggClass . 'Service $' . $serviceProp . ')',
                '    {',
                '    }',
                '',
                '    public function subjectRoot(): string',
                '    {',
                '        return ' . $this->q($this->subjectRoot($handleAgg)) . ';',
                '    }',
                '',
                '    public function handle(StoredEvent $event): void',
                '    {',
                '        if ($event->type !== ' . $handleEventsClass . '::' . $this->eventConst($event) . ') {',
                '            return;',
                '        }',
                '',
                '        $command = new ' . $cmdClass . '(' . implode(', ', $args) . ');',
                '',
                '        try {',
                '            $this->' . $serviceProp . '->' . $method . '($command, $event->correlationId);',
                '        } catch (\\Throwable $e) {',
                '            fwrite(STDERR, sprintf(\'policy ' . $policy->name . ' failed: %s%s\', $e->getMessage(), PHP_EOL));',
                '        }',
                '    }',
                '}',
            ];

            $project->add('src/' . $emitCtx . '/Policy/' . $class . '.php', $this->phpFile($policyNs, $uses, $lines));
        }
    }

    // ---- Worker: one observe stream fans out to projectors + policies -------------

    private function emitObserveWorker(GeneratedProject $project, string $ns, Model $model): void
    {
        $projectors = [];
        foreach ($model->boundedContexts as $context) {
            foreach ($context->readModels as $readModel) {
                $projectors[] = [
                    'class' => $ns . '\\' . Str::studly($context->name) . '\\ReadModel\\' . Str::studly($readModel->name) . 'Projector',
                    'prop' => Str::camel($readModel->name) . 'Projector',
                    'short' => Str::studly($readModel->name) . 'Projector',
                ];
            }
        }
        $policies = [];
        foreach ($model->policies as $policy) {
            $handleAgg = $model->aggregate($policy->handleContext, $policy->handleAggregate);
            $emitAgg = $model->aggregate($policy->emitContext, $policy->emitAggregate);
            if (
                $handleAgg === null || $emitAgg === null
                || $handleAgg->event($policy->handleEvent) === null
                || $this->commandOf($emitAgg, $policy->emitCommand) === null
            ) {
                continue;
            }
            $policies[] = [
                'class' => $ns . '\\' . Str::studly($policy->emitContext) . '\\Policy\\' . Str::studly($policy->name) . 'Policy',
                'prop' => Str::camel($policy->name) . 'Policy',
                'short' => Str::studly($policy->name) . 'Policy',
            ];
        }

        $uses = [
            $ns . '\\Shared\\EventStore\\EsdbClientFactory',
            $ns . '\\Shared\\EventStore\\StoredEvent',
            'Symfony\\Component\\Console\\Attribute\\AsCommand',
            'Symfony\\Component\\Console\\Command\\Command',
            'Symfony\\Component\\Console\\Input\\InputInterface',
            'Symfony\\Component\\Console\\Output\\OutputInterface',
            'Thenativeweb\\Eventsourcingdb\\Bound',
            'Thenativeweb\\Eventsourcingdb\\BoundType',
            'Thenativeweb\\Eventsourcingdb\\Client',
            'Thenativeweb\\Eventsourcingdb\\ObserveEventsOptions',
        ];
        foreach ([...$projectors, ...$policies] as $consumer) {
            $uses[] = $consumer['class'];
        }

        $ctorArgs = ['        private readonly EsdbClientFactory $clientFactory,'];
        foreach ($projectors as $projector) {
            $ctorArgs[] = '        private readonly ' . $projector['short'] . ' $' . $projector['prop'] . ',';
        }
        foreach ($policies as $policy) {
            $ctorArgs[] = '        private readonly ' . $policy['short'] . ' $' . $policy['prop'] . ',';
        }

        $lines = [
            '/**',
            ' * The long-running worker: one EventSourcingDB observe stream fans out to the',
            ' * read-model projectors (resuming after their max projected revision) and the',
            ' * policies (no cursor - they re-run from the start of the stream on boot).',
            ' */',
            '#[AsCommand(\'app:observe\', \'Run projections and policies against the event stream.\')]',
            'final class ObserveCommand extends Command',
            '{',
            '    public function __construct(',
        ];
        $lines = array_merge($lines, $ctorArgs);
        $lines[] = '    ) {';
        $lines[] = '        parent::__construct();';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    protected function execute(InputInterface $input, OutputInterface $output): int';
        $lines[] = '    {';
        $lines[] = '        // A dedicated client: the SDK allows one in-flight request per client,';
        $lines[] = '        // and dispatched commands read/write while the stream is open.';
        $lines[] = '        $client = $this->clientFactory->create();';
        $lines[] = '        $this->waitForStore($client, $output);';
        $lines[] = '';
        $lines[] = '        $projectors = [' . implode(', ', array_map(static fn (array $p): string => '$this->' . $p['prop'], $projectors)) . '];';
        $lines[] = '        $policies = [' . implode(', ', array_map(static fn (array $p): string => '$this->' . $p['prop'], $policies)) . '];';
        $lines[] = '';
        $lines[] = '        foreach ($projectors as $projector) {';
        $lines[] = '            $projector->setup();';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        $consumers = [];';
        $lines[] = '        $cursors = []; // last handled event id per consumer (-1 = from the start)';
        $lines[] = '        foreach ($projectors as $projector) {';
        $lines[] = '            $consumers[] = $projector;';
        $lines[] = '            $cursors[] = $projector->lowerBound() ?? -1;';
        $lines[] = '        }';
        $lines[] = '        foreach ($policies as $policy) {';
        $lines[] = '            $consumers[] = $policy;';
        $lines[] = '            $cursors[] = -1;';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        if ($consumers === []) {';
        $lines[] = '            $output->writeln(\'Nothing to observe.\');';
        $lines[] = '';
        $lines[] = '            return Command::SUCCESS;';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        $streamFrom = min($cursors);';
        $lines[] = '        while (true) {';
        $lines[] = '            try {';
        $lines[] = '                $options = new ObserveEventsOptions(';
        $lines[] = '                    recursive: true,';
        $lines[] = '                    lowerBound: $streamFrom >= 0 ? new Bound((string) $streamFrom, BoundType::EXCLUSIVE) : null,';
        $lines[] = '                );';
        $lines[] = '                foreach ($client->observeEvents(\'/\', $options) as $cloudEvent) {';
        $lines[] = '                    $event = StoredEvent::fromCloudEvent($cloudEvent);';
        $lines[] = '                    $eventId = (int) $event->id;';
        $lines[] = '                    foreach ($consumers as $i => $consumer) {';
        $lines[] = '                        if ($eventId <= $cursors[$i] || !$this->accepts($consumer->subjectRoot(), $event->subject)) {';
        $lines[] = '                            continue;';
        $lines[] = '                        }';
        $lines[] = '                        try {';
        $lines[] = '                            $consumer->handle($event);';
        $lines[] = '                        } catch (\\Throwable $e) {';
        $lines[] = '                            // A failing consumer skips the event after logging - the stream must keep flowing.';
        $lines[] = '                            $output->writeln(sprintf(\'%s failed on event %s: %s\', $consumer::class, $event->id, $e->getMessage()));';
        $lines[] = '                        }';
        $lines[] = '                        $cursors[$i] = $eventId;';
        $lines[] = '                    }';
        $lines[] = '                    $streamFrom = $eventId;';
        $lines[] = '                }';
        $lines[] = '            } catch (\\RuntimeException $e) {';
        $lines[] = '                $output->writeln(sprintf(\'Observe stream dropped (%s), reconnecting...\', $e->getMessage()));';
        $lines[] = '            }';
        $lines[] = '            sleep(2);';
        $lines[] = '        }';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function accepts(string $root, string $subject): bool';
        $lines[] = '    {';
        $lines[] = '        return $root === \'/\' || $subject === $root || str_starts_with($subject, $root . \'/\');';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    private function waitForStore(Client $client, OutputInterface $output): void';
        $lines[] = '    {';
        $lines[] = '        for ($attempt = 1; $attempt <= 60; $attempt++) {';
        $lines[] = '            try {';
        $lines[] = '                $client->ping();';
        $lines[] = '';
        $lines[] = '                return;';
        $lines[] = '            } catch (\\RuntimeException) {';
        $lines[] = '                $output->writeln(sprintf(\'Waiting for EventSourcingDB (%d)...\', $attempt));';
        $lines[] = '                sleep(2);';
        $lines[] = '            }';
        $lines[] = '        }';
        $lines[] = '';
        $lines[] = '        throw new \\RuntimeException(\'EventSourcingDB did not become ready in time\');';
        $lines[] = '    }';
        $lines[] = '}';

        $project->add('src/Shared/Console/ObserveCommand.php', $this->phpFile($ns . '\\Shared\\Console', array_values(array_unique($uses)), $lines));
    }

    // ---- GWT scenarios -> PHPUnit decider tests ------------------------------------

    private function emitTests(GeneratedProject $project, string $ns, Model $model): void
    {
        foreach ($model->features as $feature) {
            $aggregate = $model->aggregate($feature->boundedContext, $feature->aggregate);
            if ($aggregate === null) {
                continue;
            }

            $ctx = Str::studly($feature->boundedContext);
            $testNs = $ns . '\\Tests\\' . $ctx;
            $domainNs = $ns . '\\' . $ctx . '\\Domain';
            $aggClass = Str::studly($aggregate->name);
            $class = Str::studly($feature->name) . 'Test';

            $uses = [
                $domainNs . '\\' . $aggClass,
                $domainNs . '\\' . $aggClass . 'State',
                $domainNs . '\\Event\\' . $aggClass . 'Events',
                $ns . '\\Shared\\EventStore\\DomainEvent',
                'PHPUnit\\Framework\\TestCase',
            ];
            foreach ($feature->scenarios as $scenario) {
                $command = $this->commandOf($aggregate, $scenario->commandName);
                if ($command !== null) {
                    $uses[] = $domainNs . '\\Command\\' . Str::studly($command->name);
                }
                if ($scenario->isRejection()) {
                    $uses[] = $ns . '\\Shared\\DomainViolation';
                }
            }

            $lines = [
                'final class ' . $class . ' extends TestCase',
                '{',
            ];

            $first = true;
            foreach ($feature->scenarios as $scenario) {
                $method = $this->scenarioMethod($aggregate, $scenario, $aggClass);
                if ($method === []) {
                    continue;
                }
                if (!$first) {
                    $lines[] = '';
                }
                $first = false;
                $lines = array_merge($lines, $method);
            }

            $lines[] = '}';

            $project->add(
                'tests/' . $ctx . '/' . $class . '.php',
                $this->phpFile($testNs, array_values(array_unique($uses)), $lines),
            );
        }
    }

    /** @return list<string> */
    private function scenarioMethod(Aggregate $aggregate, Scenario $scenario, string $aggClass): array
    {
        $command = $this->commandOf($aggregate, $scenario->commandName);
        $event = $command !== null ? $aggregate->event((string) $command->primaryEvent()) : null;
        if ($command === null || $event === null) {
            return [];
        }

        $eventsClass = $aggClass . 'Events';
        $stateClass = $aggClass . 'State';
        $fn = Str::camel($command->name);
        $id = $this->scenarioId($aggregate, $scenario, $event);

        $lines = ['    public function test' . Str::studly($scenario->name) . '(): void', '    {'];
        if ($scenario->isRejection() && $scenario->rejectionReason !== null) {
            $lines[] = '        // ' . $scenario->rejectionReason;
        }

        $lines[] = '        $state = new ' . $stateClass . '(' . $this->q($id) . ');';
        foreach ($scenario->given as $example) {
            $ev = $aggregate->event($example->event);
            if ($ev === null) {
                continue;
            }
            $lines[] = '        $state->apply(' . $eventsClass . '::' . $this->eventConst($ev) . ', ' . $this->dataLiteral($ev, $example->data) . ');';
        }

        $callArgs = ['$state', 'new ' . Str::studly($command->name) . '(' . implode(', ', $this->testCommandArgs($aggregate, $scenario, $command, $event)) . ')'];
        $guard = $this->commandGuard($aggregate, $command);
        if ($command->lifecycle !== Lifecycle::Create) {
            // A temporal guard adds a clock parameter; pass a fixed far-future value
            // so it never masks the transition/state guard the scenario is testing.
            if ($guard['usesToday']) {
                $callArgs[] = "'2099-01-01'";
            }
            if ($guard['usesNow']) {
                $callArgs[] = "'2099-01-01T00:00:00+00:00'";
            }
        }
        $call = $aggClass . '::' . $fn . '(' . implode(', ', $callArgs) . ')';

        if ($scenario->isRejection()) {
            $lines[] = '';
            $lines[] = '        $this->expectException(DomainViolation::class);';
            $lines[] = '';
            $lines[] = '        ' . $call . ';';
            $lines[] = '    }';

            return $lines;
        }

        $lines[] = '';
        $lines[] = '        $events = ' . $call . ';';
        $lines[] = '';

        // Assert only the fields the scenario declares (subset match). A real event
        // carries more than the `then` states — a state-machine status, echoed command
        // inputs — so asserting the full payload against a fabricated default fails
        // spuriously. Undeclared fields are the decider's business, not the scenario's.
        $expected = [];
        foreach ($scenario->thenEvents as $example) {
            $ev = $aggregate->event($example->event);
            if ($ev !== null) {
                $expected[] = [$ev, $example];
            }
        }
        $lines[] = '        self::assertCount(' . count($expected) . ', $events);';
        foreach ($expected as $i => [$ev, $example]) {
            $lines[] = '        self::assertSame(' . $eventsClass . '::' . $this->eventConst($ev) . ', $events[' . $i . ']->type);';
            foreach ($ev->data as $field) {
                if (!array_key_exists($field->name, $example->data)) {
                    continue;
                }
                $lines[] = '        self::assertSame(' . var_export($example->data[$field->name], true)
                    . ', $events[' . $i . ']->data[' . $this->q(Str::camel($field->name)) . ']);';
            }
        }
        $lines[] = '    }';

        return $lines;
    }

    /** The command constructor arguments of a scenario, in command-schema order. */
    private function testCommandArgs(Aggregate $aggregate, Scenario $scenario, Command $command, Event $event): array
    {
        $createSource = [];
        if ($command->lifecycle === Lifecycle::Create) {
            foreach ($scenario->thenEvents as $example) {
                if ($example->event === $event->name) {
                    $createSource = $example->data;
                    break;
                }
            }
        }

        $args = [];
        foreach ($command->data as $field) {
            $value = $scenario->commandData[$field->name] ?? $createSource[$field->name] ?? null;
            $args[] = $value === null && !array_key_exists($field->name, $scenario->commandData)
                ? Types::defaultLiteral($field)
                : var_export($value, true);
        }

        return $args;
    }

    /** A `['key' => value, ...]` payload literal over an event's schema (camelCase keys). */
    private function dataLiteral(Event $event, array $data): string
    {
        $parts = [];
        foreach ($event->data as $field) {
            $value = array_key_exists($field->name, $data)
                ? var_export($data[$field->name], true)
                : Types::defaultLiteral($field);
            $parts[] = $this->q(Str::camel($field->name)) . ' => ' . $value;
        }

        return $parts === [] ? '[]' : '[' . implode(', ', $parts) . ']';
    }

    /** The aggregate id a scenario operates on: command data, else the then/given events. */
    private function scenarioId(Aggregate $aggregate, Scenario $scenario, Event $event): string
    {
        $idField = $aggregate->identityField;
        if (isset($scenario->commandData[$idField])) {
            return (string) $scenario->commandData[$idField];
        }
        foreach ($scenario->thenEvents as $example) {
            if ($example->event === $event->name && isset($example->data[$idField])) {
                return (string) $example->data[$idField];
            }
        }
        foreach ($scenario->given as $example) {
            if (isset($example->data[$idField])) {
                return (string) $example->data[$idField];
            }
        }

        return '';
    }

    // ---- 0004 domain-console contract (dev-only surface) ----------------------------

    /** @param array<string, mixed> $options */
    private function emitDevEndpoint(GeneratedProject $project, string $ns, Model $model, array $options): void
    {
        $project->add('src/Dev/catalog.json', $this->catalogJson($model));
        $project->add('src/Dev/source.bpmn', (string) ($options['bpmnSource'] ?? ''));
        $project->add('src/Dev/DevController.php', $this->phpFile($ns . '\\Dev', [
            $ns . '\\Shared\\EventStore\\EventStore',
            'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
            'Symfony\\Component\\HttpFoundation\\JsonResponse',
            'Symfony\\Component\\HttpFoundation\\Response',
            'Symfony\\Component\\Routing\\Attribute\\Route',
        ], [
            '/**',
            ' * Dev-only window onto the app for an external domain console (0004): the',
            ' * model catalog, the authoring BPMN and the raw event stream. Not part of',
            ' * the domain API — never expose it in production.',
            ' */',
            'final class DevController extends AbstractController',
            '{',
            '    public function __construct(private readonly EventStore $eventStore)',
            '    {',
            '    }',
            '',
            "    #[Route('/_dev/catalog', name: 'dev_catalog', methods: ['GET'])]",
            '    public function catalog(): JsonResponse',
            '    {',
            "        return JsonResponse::fromJsonString((string) file_get_contents(__DIR__ . '/catalog.json'));",
            '    }',
            '',
            "    #[Route('/_dev/bpmn', name: 'dev_bpmn', methods: ['GET'])]",
            '    public function bpmn(): Response',
            '    {',
            "        return new Response((string) file_get_contents(__DIR__ . '/source.bpmn'), Response::HTTP_OK, ['Content-Type' => 'application/xml']);",
            '    }',
            '',
            "    #[Route('/_dev/events', name: 'dev_events', methods: ['GET'])]",
            '    public function events(): JsonResponse',
            '    {',
            '        try {',
            '            $rows = $this->eventStore->latest(50);',
            '        } catch (\\Throwable) {',
            '            $rows = [];',
            '        }',
            '',
            '        return new JsonResponse($rows);',
            '    }',
            '}',
        ]));
    }

    // ---- Skeleton (framework + docker wiring) -----------------------------------------

    private function emitSkeleton(GeneratedProject $project, string $ns, string $appName, Model $model, string $source): void
    {
        $project->add('composer.json', $this->composerJson($appName));
        $project->add('Dockerfile', $this->dockerfile());
        $project->add('compose.yaml', $this->composeYaml($appName));
        $project->add('.dockerignore', "vendor/\nvar/\n.env.local\n");
        $project->add('.env', $this->dotEnv($appName));
        $project->add('Makefile', $this->makefile());
        $project->add('bin/console', $this->binConsole());
        $project->add('public/index.php', $this->publicIndex());
        $project->add('src/Kernel.php', $this->kernel());
        $project->add('src/Shared/DomainViolation.php', $this->phpFile($ns . '\\Shared', [], [
            '/** Base for domain-rule violations (state machine + guards). Mapped to HTTP 409. */',
            'abstract class DomainViolation extends \\RuntimeException',
            '{',
            '    abstract public function errorCode(): string;',
            '',
            '    /** @return array<string, string> */',
            '    public function details(): array',
            '    {',
            '        return [\'errorCode\' => $this->errorCode()];',
            '    }',
            '}',
        ]));
        $project->add('src/Shared/IllegalTransition.php', $this->phpFile($ns . '\\Shared', [], [
            '/** A command was issued from a state the aggregate does not admit it from (0001). */',
            'final class IllegalTransition extends DomainViolation',
            '{',
            '    public function __construct(public readonly string $command, public readonly string $state)',
            '    {',
            "        parent::__construct(sprintf('%s is not allowed while \"%s\"', \$command, \$state));",
            '    }',
            '',
            "    public function errorCode(): string",
            '    {',
            "        return 'ILLEGAL_TRANSITION';",
            '    }',
            '',
            '    /** @return array<string, string> */',
            '    public function details(): array',
            '    {',
            "        return ['errorCode' => \$this->errorCode(), 'command' => \$this->command];",
            '    }',
            '}',
        ]));
        $project->add('src/Shared/GuardViolation.php', $this->phpFile($ns . '\\Shared', [], [
            '/** A command precondition (FEEL guard) was not met (0002). */',
            'final class GuardViolation extends DomainViolation',
            '{',
            '    public function __construct(public readonly string $command, public readonly string $requirement)',
            '    {',
            "        parent::__construct(sprintf('%s requires: %s', \$command, \$requirement));",
            '    }',
            '',
            "    public function errorCode(): string",
            '    {',
            "        return 'GUARD_VIOLATION';",
            '    }',
            '',
            '    /** @return array<string, string> */',
            '    public function details(): array',
            '    {',
            "        return ['errorCode' => \$this->errorCode(), 'command' => \$this->command];",
            '    }',
            '}',
        ]));
        $project->add('src/Shared/ConcurrencyViolation.php', $this->phpFile($ns . '\\Shared', [], [
            '/** A store write precondition failed: create on an existing subject or mutate on an unknown one. */',
            'final class ConcurrencyViolation extends DomainViolation',
            '{',
            '    public function __construct(public readonly string $subject)',
            '    {',
            "        parent::__construct(sprintf('conflicting write on \"%s\"', \$subject));",
            '    }',
            '',
            "    public function errorCode(): string",
            '    {',
            "        return 'CONCURRENCY_CONFLICT';",
            '    }',
            '}',
        ]));
        $project->add('src/Shared/Http/CorsSubscriber.php', $this->corsSubscriber($ns));
        $project->add('src/Shared/EventStore/DomainEvent.php', $this->domainEvent());
        $project->add('src/Shared/EventStore/StoredEvent.php', $this->storedEvent());
        $project->add('src/Shared/EventStore/EventConsumer.php', $this->eventConsumer());
        $project->add('src/Shared/EventStore/EsdbClientFactory.php', $this->esdbClientFactory());
        $project->add('src/Shared/EventStore/EventStore.php', $this->eventStoreClass());
        $project->add('src/Shared/Mongo/MongoFactory.php', $this->mongoFactory());
        $project->add('config/bundles.php', $this->bundles());
        $project->add('config/packages/framework.yaml', $this->frameworkYaml());
        $project->add('config/routes.yaml', $this->routesYaml());
        $project->add('config/services.yaml', $this->servicesYaml($source));
        $project->add('phpunit.xml.dist', $this->phpunitXml());
        $project->add('README.md', $this->appReadme($appName));
    }

    private function composerJson(string $appName): string
    {
        $name = preg_replace('/[^a-z0-9-]+/', '-', strtolower($appName)) ?? 'app';

        return <<<JSON
        {
            "name": "esdm2symfony/{$name}-esdb-app",
            "description": "Generated by esdm2symfony (symfony-eventsourcingdb target). Do not edit by hand; regenerate from the ESDM model.",
            "type": "project",
            "require": {
                "php": ">=8.2",
                "ext-curl": "*",
                "ext-mongodb": "*",
                "ext-sodium": "*",
                "mongodb/mongodb": "^2.0",
                "symfony/console": "^7.0",
                "symfony/dotenv": "^7.0",
                "symfony/framework-bundle": "^7.0",
                "symfony/runtime": "^7.0",
                "symfony/uid": "^7.0",
                "symfony/yaml": "^7.0",
                "thenativeweb/eventsourcingdb": "^1.4"
            },
            "require-dev": {
                "phpunit/phpunit": "^11.0"
            },
            "autoload": {
                "psr-4": {
                    "App\\\\": "src/"
                }
            },
            "autoload-dev": {
                "psr-4": {
                    "App\\\\Tests\\\\": "tests/"
                }
            },
            "config": {
                "allow-plugins": {
                    "php-http/discovery": true,
                    "symfony/runtime": true
                },
                "sort-packages": true
            }
        }

        JSON;
    }

    private function dockerfile(): string
    {
        return <<<'DOCKER'
        FROM php:8.3-cli

        RUN apt-get update \
            && apt-get install -y --no-install-recommends libssl-dev pkg-config unzip git $PHPIZE_DEPS \
            && pecl install mongodb \
            && docker-php-ext-enable mongodb \
            && rm -rf /var/lib/apt/lists/*

        COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

        WORKDIR /app
        COPY . .
        RUN composer install --no-interaction --prefer-dist --no-progress

        EXPOSE 8000
        CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]

        DOCKER;
    }

    private function composeYaml(string $appName): string
    {
        $yaml = <<<'YAML'
        # Generated stack: esdb (EventSourcingDB event store + UI), mongo (read models),
        # api (write/read HTTP), worker (projections + policies).
        services:
          esdb:
            image: thenativeweb/eventsourcingdb:1.2.0
            command:
              - run
              - --api-token=secret
              - --data-directory-temporary
              - --http-enabled
              - --https-enabled=false
              - --with-ui
            ports:
              - "3000:3000"

          mongo:
            image: mongo:7
            ports:
              - "27018:27017"
            volumes:
              - mongo-data:/data/db

          api:
            build: .
            environment:
              APP_ENV: dev
              APP_DEBUG: "1"
              ESDB_URL: "http://esdb:3000"
              ESDB_API_TOKEN: secret
              MONGO_URL: "mongodb://mongo:27017"
              MONGO_DB: __APP_NAME__
            depends_on:
              - esdb
              - mongo
            ports:
              - "8080:8000"

          worker:
            build: .
            environment:
              APP_ENV: dev
              APP_DEBUG: "1"
              ESDB_URL: "http://esdb:3000"
              ESDB_API_TOKEN: secret
              MONGO_URL: "mongodb://mongo:27017"
              MONGO_DB: __APP_NAME__
            depends_on:
              - esdb
              - mongo
            command: ["php", "bin/console", "app:observe"]
            restart: unless-stopped

        # Domain console: this stack serves the 0004 dev contract (/_dev/*) — point the
        # esdm-vue-reader viewer at http://localhost:8080 for commands / read models / events.

        volumes:
          mongo-data:

        YAML;

        $name = preg_replace('/[^a-z0-9-]+/', '-', strtolower($appName)) ?? 'app';

        return str_replace('__APP_NAME__', $name, $yaml);
    }

    private function dotEnv(string $appName): string
    {
        $name = preg_replace('/[^a-z0-9-]+/', '-', strtolower($appName)) ?? 'app';

        return <<<ENV
        APP_ENV=dev
        APP_DEBUG=1
        APP_SECRET=2f1c0d9e8b7a6f5e4d3c2b1a09f8e7d6
        ESDB_URL=http://esdb:3000
        ESDB_API_TOKEN=secret
        MONGO_URL=mongodb://mongo:27017
        MONGO_DB={$name}

        ENV;
    }

    private function makefile(): string
    {
        return <<<'MAKE'
        .PHONY: up down logs api-logs worker-logs

        up:
        	docker compose up -d --build

        down:
        	docker compose down -v

        logs:
        	docker compose logs -f

        api-logs:
        	docker compose logs -f api

        worker-logs:
        	docker compose logs -f worker

        MAKE;
    }

    private function binConsole(): string
    {
        return <<<'PHP'
        #!/usr/bin/env php
        <?php

        use App\Kernel;
        use Symfony\Bundle\FrameworkBundle\Console\Application;

        require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

        return static function (array $context): Application {
            $kernel = new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);

            return new Application($kernel);
        };

        PHP;
    }

    private function publicIndex(): string
    {
        return <<<'PHP'
        <?php

        use App\Kernel;

        require_once dirname(__DIR__) . '/vendor/autoload_runtime.php';

        return static function (array $context): Kernel {
            return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
        };

        PHP;
    }

    private function kernel(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App;

        use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
        use Symfony\Component\HttpKernel\Kernel as BaseKernel;

        final class Kernel extends BaseKernel
        {
            use MicroKernelTrait;
        }

        PHP;
    }

    private function corsSubscriber(string $ns): string
    {
        return $this->phpFile($ns . '\\Shared\\Http', [
            'Symfony\\Component\\EventDispatcher\\EventSubscriberInterface',
            'Symfony\\Component\\HttpFoundation\\Response',
            'Symfony\\Component\\HttpKernel\\Event\\RequestEvent',
            'Symfony\\Component\\HttpKernel\\Event\\ResponseEvent',
            'Symfony\\Component\\HttpKernel\\KernelEvents',
        ], [
            '/**',
            ' * Dev-stack CORS so an external domain console (0004) can call the API',
            ' * cross-origin. Production deployments should drop or restrict this.',
            ' */',
            'final class CorsSubscriber implements EventSubscriberInterface',
            '{',
            '    public static function getSubscribedEvents(): array',
            '    {',
            "        return [KernelEvents::REQUEST => ['onRequest', 250], KernelEvents::RESPONSE => 'onResponse'];",
            '    }',
            '',
            '    public function onRequest(RequestEvent $event): void',
            '    {',
            "        if (\$event->getRequest()->getMethod() === 'OPTIONS') {",
            "            \$event->setResponse(new Response('', Response::HTTP_NO_CONTENT));",
            '        }',
            '    }',
            '',
            '    public function onResponse(ResponseEvent $event): void',
            '    {',
            '        $headers = $event->getResponse()->headers;',
            "        \$headers->set('Access-Control-Allow-Origin', '*');",
            "        \$headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');",
            "        \$headers->set('Access-Control-Allow-Headers', 'Content-Type');",
            '    }',
            '}',
        ]);
    }

    private function domainEvent(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\EventStore;

        /** One domain event the decider produced: CloudEvents `type` plus its payload. */
        final class DomainEvent
        {
            /** @param array<string, mixed> $data */
            public function __construct(
                public readonly string $type,
                public readonly array $data,
            ) {
            }
        }

        PHP;
    }

    private function storedEvent(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\EventStore;

        use Thenativeweb\Eventsourcingdb\CloudEvent;

        /** One event read back from EventSourcingDB, with the nimbus envelope unwrapped. */
        final class StoredEvent
        {
            /** @param array<string, mixed> $payload */
            public function __construct(
                public readonly string $id,
                public readonly string $subject,
                public readonly string $type,
                public readonly array $payload,
                public readonly string $correlationId,
                public readonly \DateTimeImmutable $time,
            ) {
            }

            public static function fromCloudEvent(CloudEvent $event): self
            {
                $data = $event->data;
                // Events written by this app (or any nimbus-compatible writer) wrap the
                // business payload as { payload, nimbusMeta: { correlationid } }.
                if (isset($data['payload'], $data['nimbusMeta'])) {
                    return new self(
                        $event->id,
                        $event->subject,
                        $event->type,
                        (array) $data['payload'],
                        (string) ($data['nimbusMeta']['correlationid'] ?? ''),
                        $event->time,
                    );
                }

                return new self($event->id, $event->subject, $event->type, $data, '', $event->time);
            }
        }

        PHP;
    }

    private function eventConsumer(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\EventStore;

        /** A worker-side consumer of the event stream: read-model projectors and policies. */
        interface EventConsumer
        {
            /** Subject filter for the observe stream ('/' consumes every aggregate). */
            public function subjectRoot(): string;

            public function handle(StoredEvent $event): void;
        }

        PHP;
    }

    private function esdbClientFactory(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\EventStore;

        use Thenativeweb\Eventsourcingdb\Client;

        /**
         * Builds EventSourcingDB clients. The SDK allows one in-flight request per
         * client, so the observe worker gets its own instance besides the shared one.
         */
        final class EsdbClientFactory
        {
            public function __construct(
                private readonly string $url,
                private readonly string $apiToken,
            ) {
            }

            public function create(): Client
            {
                return new Client($this->url, $this->apiToken);
            }
        }

        PHP;
    }

    private function eventStoreClass(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\EventStore;

        use App\Shared\ConcurrencyViolation;
        use Thenativeweb\Eventsourcingdb\Client;
        use Thenativeweb\Eventsourcingdb\EventCandidate;
        use Thenativeweb\Eventsourcingdb\IsSubjectPopulated;
        use Thenativeweb\Eventsourcingdb\IsSubjectPristine;
        use Thenativeweb\Eventsourcingdb\Order;
        use Thenativeweb\Eventsourcingdb\ReadEventsOptions;

        /**
         * The EventSourcingDB event store: appends the decider's events under a subject
         * with a write precondition and replays subjects for command handling. The wire
         * format matches the nimbus codegens: data = { payload, nimbusMeta }.
         */
        final class EventStore
        {
            public const PRISTINE = 'pristine';
            public const POPULATED = 'populated';

            public function __construct(
                private readonly Client $client,
                private readonly string $source,
            ) {
            }

            /** @param list<DomainEvent> $events */
            public function append(string $subject, array $events, string $correlationId, ?string $precondition = null): void
            {
                $candidates = [];
                foreach ($events as $event) {
                    $candidates[] = new EventCandidate(
                        $this->source,
                        $subject,
                        $event->type,
                        [
                            'payload' => $event->data === [] ? new \stdClass() : $event->data,
                            'nimbusMeta' => ['correlationid' => $correlationId],
                        ],
                    );
                }

                $preconditions = match ($precondition) {
                    self::PRISTINE => [new IsSubjectPristine($subject)],
                    self::POPULATED => [new IsSubjectPopulated($subject)],
                    default => [],
                };

                try {
                    $this->client->writeEvents($candidates, $preconditions);
                } catch (\RuntimeException $e) {
                    if ($e->getCode() === 409) {
                        throw new ConcurrencyViolation($subject);
                    }

                    throw $e;
                }
            }

            /** @return \Generator<int, StoredEvent> */
            public function read(string $subject): \Generator
            {
                foreach ($this->client->readEvents($subject, new ReadEventsOptions()) as $event) {
                    yield StoredEvent::fromCloudEvent($event);
                }
            }

            /**
             * Newest events first, mapped to the uniform 0004 dev row shape. ESDB has
             * no per-subject playhead, so that field stays null.
             *
             * @return list<array<string, mixed>>
             */
            public function latest(int $limit): array
            {
                $rows = [];
                $options = new ReadEventsOptions(recursive: true, order: Order::ANTICHRONOLOGICAL);
                foreach ($this->client->readEvents('/', $options) as $event) {
                    $stored = StoredEvent::fromCloudEvent($event);
                    $segments = array_values(array_filter(
                        explode('/', $stored->subject),
                        static fn (string $s): bool => $s !== '',
                    ));
                    $rows[] = [
                        'id' => $stored->id,
                        'aggregate' => $segments[0] ?? '',
                        'aggregate_id' => $segments[count($segments) - 1] ?? '',
                        'playhead' => null,
                        'event' => $stored->type,
                        'payload' => $stored->payload,
                        'recorded_on' => $stored->time->format('Y-m-d\TH:i:s.up'),
                    ];
                    if (count($rows) >= $limit) {
                        break;
                    }
                }

                return $rows;
            }
        }

        PHP;
    }

    private function mongoFactory(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\Mongo;

        use MongoDB\Client;
        use MongoDB\Database;

        /** Builds the read-model database; plain arrays keep the row shape explicit. */
        final class MongoFactory
        {
            public function __construct(
                private readonly string $url,
                private readonly string $database,
            ) {
            }

            public function create(): Database
            {
                return (new Client($this->url))->selectDatabase($this->database, [
                    'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
                ]);
            }
        }

        PHP;
    }

    private function bundles(): string
    {
        return <<<'PHP'
        <?php

        return [
            Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true],
        ];

        PHP;
    }

    private function frameworkYaml(): string
    {
        return <<<'YAML'
        framework:
            secret: '%env(APP_SECRET)%'
            http_method_override: false
            handle_all_throwables: true
            php_errors:
                log: true
            router:
                utf8: true

        YAML;
    }

    private function routesYaml(): string
    {
        return <<<'YAML'
        controllers:
            resource: ../src/
            type: attribute

        YAML;
    }

    private function servicesYaml(string $source): string
    {
        $sourceYaml = "'" . str_replace("'", "''", $source) . "'";

        return <<<YAML
        # Generated wiring for EventSourcingDB (event store) + MongoDB (read models).
        services:
            _defaults:
                autowire: true
                autoconfigure: true
                public: false

            App\\:
                resource: '../src/'
                exclude:
                    - '../src/Kernel.php'
                    - '../src/*/Domain/'
                    - '../src/Shared/EventStore/DomainEvent.php'
                    - '../src/Shared/EventStore/StoredEvent.php'

            App\\Shared\\EventStore\\EsdbClientFactory:
                arguments: ['%env(ESDB_URL)%', '%env(ESDB_API_TOKEN)%']

            Thenativeweb\\Eventsourcingdb\\Client:
                factory: ['@App\\Shared\\EventStore\\EsdbClientFactory', 'create']

            App\\Shared\\EventStore\\EventStore:
                arguments:
                    \$source: {$sourceYaml}

            App\\Shared\\Mongo\\MongoFactory:
                arguments: ['%env(MONGO_URL)%', '%env(MONGO_DB)%']

            MongoDB\\Database:
                factory: ['@App\\Shared\\Mongo\\MongoFactory', 'create']

        YAML;
    }

    private function phpunitXml(): string
    {
        return <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                 xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
                 bootstrap="vendor/autoload.php"
                 colors="true"
                 failOnWarning="true">
            <testsuites>
                <testsuite name="domain">
                    <directory>tests</directory>
                </testsuite>
            </testsuites>
        </phpunit>

        XML;
    }

    private function appReadme(string $appName): string
    {
        return <<<MD
        # {$appName} (generated)

        This application was generated by **esdm2symfony** with the
        `symfony-eventsourcingdb` target — Symfony 7 with **EventSourcingDB** as the
        event store and **MongoDB** for read models. Do not edit it by hand — change
        the ESDM model and regenerate.

        ## Architecture

        - **Write side** (`api`): HTTP `POST /<context>/<command>` builds a command, the
          application service replays the subject (`/<aggregate>/<id>`) from
          EventSourcingDB, folds state, runs the pure decider, and appends the resulting
          events with a write precondition — a create on an existing subject or a mutate
          on an unknown one is a 409, never a lost update.
        - **Read side** (`worker`): `app:observe` streams events and projects them into
          MongoDB collections (`rm_*`), resuming after the highest projected `revision`.
        - **Policies** react to events and dispatch commands across aggregates; they run
          in the same worker without a cursor.
        - **Query side** (`api`): HTTP `GET /<context>/<query>` reads the collections.
          Reads are eventually consistent with writes.

        The event `data` envelope (`{ payload, nimbusMeta: { correlationid } }`), the
        subject scheme and the CloudEvents `type` (`domain.aggregate.event-name`) match
        the nimbus codegens, so the store is interchangeable between them.

        ## Run

        ```sh
        docker compose up -d --build
        # EventSourcingDB UI: http://localhost:3000
        curl -s -XPOST localhost:8080/<context>/<create-command> -d '{...}'
        curl -s localhost:8080/<context>/<list-query>
        curl -s 'localhost:8080/<context>/<get-query>?id=<id>'
        ```

        To rebuild a read model from zero, drop its `rm_*` collection — on the next
        worker start the projector replays the whole stream into it.

        ## Domain console

        The app serves the **domain-console contract** (esdm-extensions 0004) in dev:
        `GET /_dev/catalog` (model catalog), `GET /_dev/bpmn` (authoring diagram) and
        `GET /_dev/events` (newest slice of the event stream), plus permissive CORS. Point the
        stack-agnostic **esdm-vue-reader** viewer at `http://localhost:8080` to send commands,
        watch events and see read models update. The `/_dev/*` surface is a dev window — do
        not expose it in production.

        ## Extending the application

        Everything here is derived from the ESDM model — never edit generated code by
        hand. To change behavior, change the **model** and regenerate:

        - New behavior on the write side → add or extend **commands** and **events**
          (plus state-machine transitions and FEEL guards).
        - Reactions ("whenever X happened, do Y") → model a **policy**; it is
          generated as a stream consumer that dispatches the follow-up command.
        - Different views of the data → add or extend **read models**.

        Integrations that leave the system (brokers, mail, external APIs) subscribe
        to the event store downstream instead of hooking into generated code — every
        state change is already an event in EventSourcingDB, so consumers need
        nothing from this app but the stream.

        MD;
    }

    // ---- 0004 catalog (the app's self-description for the console) -------------------

    /**
     * Derive, per aggregate-state field, value hints from the FEEL guards that
     * reference it — so a console can offer FEEL-conform inputs. A field
     * compared to today()/now() is temporal; literals it is compared to or
     * tested against (`= "x"`, `in [...]`) become suggested values.
     *
     * @return array<string, array{temporal: ?string, values: list<string>, rules: list<string>}>
     */
    private function feelFieldHints(Aggregate $aggregate): array
    {
        if ($aggregate->stateMachine === null) {
            return [];
        }

        $acc = [];
        foreach ($aggregate->stateMachine->admits as $admit) {
            if ($admit->when === null) {
                continue;
            }
            try {
                $ast = Feel::parse($admit->when);
            } catch (\Throwable) {
                continue;
            }
            $this->collectFeelHints($ast, $admit->when, $acc);
        }

        $hints = [];
        foreach ($acc as $field => $data) {
            $hints[$field] = [
                'temporal' => $data['temporal'] ?? null,
                'values' => array_values(array_unique($data['values'] ?? [])),
                'rules' => array_values(array_unique($data['rules'] ?? [])),
            ];
        }

        return $hints;
    }

    /** @param array<string, mixed> $node */
    private function collectFeelHints(array $node, string $rule, array &$acc): void
    {
        switch ($node['t'] ?? '') {
            case 'and':
            case 'or':
                $this->collectFeelHints($node['l'], $rule, $acc);
                $this->collectFeelHints($node['r'], $rule, $acc);
                break;
            case 'not':
                $this->collectFeelHints($node['e'], $rule, $acc);
                break;
            case 'bin':
                $this->recordFeelOperand($node['l'], $node['r'], $rule, $acc);
                $this->recordFeelOperand($node['r'], $node['l'], $rule, $acc);
                break;
            case 'in':
                if (($node['e']['t'] ?? '') === 'id') {
                    foreach ($node['list'] as $item) {
                        $this->addFeelLiteral($acc, $node['e']['name'], $item, $rule);
                    }
                }
                break;
        }
    }

    /**
     * @param array<string, mixed> $field the candidate identifier operand
     * @param array<string, mixed> $other the operand it is compared against
     */
    private function recordFeelOperand(array $field, array $other, string $rule, array &$acc): void
    {
        if (($field['t'] ?? '') !== 'id') {
            return;
        }
        $name = $field['name'];
        if (($other['t'] ?? '') === 'call') {
            $acc[$name]['temporal'] = $other['fn'] === 'now' ? 'datetime' : 'date';
            $acc[$name]['rules'][] = $rule;

            return;
        }
        $this->addFeelLiteral($acc, $name, $other, $rule);
    }

    /** @param array<string, mixed> $literal */
    private function addFeelLiteral(array &$acc, string $field, array $literal, string $rule): void
    {
        $value = match ($literal['t'] ?? '') {
            'str', 'num' => (string) $literal['v'],
            'bool' => $literal['v'] ? 'true' : 'false',
            default => null,
        };
        if ($value === null) {
            return;
        }
        $acc[$field]['values'][] = $value;
        $acc[$field]['rules'][] = $rule;
    }

    private function catalogJson(Model $model): string
    {
        $contexts = [];
        foreach ($model->boundedContexts as $context) {
            $base = '/' . $context->name;

            $commands = [];
            foreach ($context->aggregates as $aggregate) {
                $feelHints = $this->feelFieldHints($aggregate);
                foreach ($aggregate->commands as $command) {
                    $fields = [];
                    foreach ($command->data as $field) {
                        $fields[] = ['name' => $field->name, 'type' => $field->jsonType, 'feel' => $feelHints[$field->name] ?? null];
                    }
                    $guard = null;
                    $admit = $aggregate->stateMachine?->admitFor($command->name);
                    if ($admit !== null && ($admit->from !== [] || $admit->when !== null)) {
                        $guard = ['from' => $admit->from, 'when' => $admit->when];
                    }
                    $commands[] = [
                        'name' => $command->name,
                        'lifecycle' => $command->lifecycle->value,
                        'path' => $base . '/' . $command->name,
                        'fields' => $fields,
                        'guard' => $guard,
                    ];
                }
            }

            $queries = [];
            foreach ($context->queries as $query) {
                $params = [];
                foreach ($query->parameters as $field) {
                    $params[] = ['name' => $field->name, 'type' => $field->jsonType];
                }
                $queries[] = [
                    'name' => $query->name,
                    'path' => $base . '/' . $query->name,
                    'kind' => $query->parameters->fields !== [] ? 'get' : 'list',
                    'params' => $params,
                    'readModel' => $query->readModel,
                ];
            }

            $readModels = [];
            foreach ($context->readModels as $readModel) {
                $columns = [];
                foreach ($readModel->columns as $column) {
                    // Use the snake_case document key — that is the key the finder returns.
                    $columns[] = ['name' => Str::snake($column->name), 'type' => $column->jsonType, 'identity' => $column->isIdentity];
                }
                $listPath = null;
                foreach ($context->queries as $query) {
                    if ($query->readModel === $readModel->name && $query->parameters->fields === []) {
                        $listPath = $base . '/' . $query->name;
                        break;
                    }
                }

                // Attach the aggregate's state machine when this read model carries
                // a `status` column — so the console can show the lifecycle per row.
                $stateMachine = null;
                $projectedAggregate = null;
                foreach ($readModel->projections as $projection) {
                    $projectedAggregate = $this->aggregateOf($context, $projection->aggregate);
                    if ($projectedAggregate !== null) {
                        break;
                    }
                }
                if ($projectedAggregate?->stateMachine !== null && $readModel->columns->has('status')) {
                    $machine = $projectedAggregate->stateMachine;
                    $admits = [];
                    foreach ($machine->admits as $admit) {
                        $cmd = $this->commandOf($projectedAggregate, $admit->command);
                        $admits[] = [
                            'command' => $admit->command,
                            'from' => $admit->from,
                            'when' => $admit->when,
                            'to' => $cmd !== null ? $machine->transitionTarget((string) $cmd->primaryEvent()) : null,
                        ];
                    }
                    $stateMachine = [
                        'statusColumn' => 'status',
                        'initial' => $machine->initial,
                        'states' => array_map(static fn ($s): array => ['name' => $s->name, 'final' => $s->final], $machine->states),
                        'admits' => $admits,
                    ];
                }

                $readModels[] = ['name' => $readModel->name, 'columns' => $columns, 'listPath' => $listPath, 'stateMachine' => $stateMachine];
            }

            $contexts[] = [
                'name' => $context->name,
                'commands' => $commands,
                'queries' => $queries,
                'readModels' => $readModels,
            ];
        }

        return (string) json_encode(['domain' => $model->domain, 'contexts' => $contexts], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    // ---- Helpers ----------------------------------------------------------------------

    private function subjectRoot(Aggregate $aggregate): string
    {
        return '/' . $aggregate->name;
    }

    private function projectionSubject(BoundedContext $context, ReadModel $readModel): string
    {
        $aggregates = [];
        foreach ($this->projectedEvents($context, $readModel) as $event) {
            $aggregates[$event->aggregate] = true;
        }
        $names = array_keys($aggregates);

        return count($names) === 1 ? '/' . $names[0] : '/';
    }

    /** The `use` lines of the event-constant classes a projector needs. */
    private function eventsClassesOf(string $ns, BoundedContext $context, array $events): array
    {
        $uses = [];
        foreach ($events as $event) {
            $ctx = Str::studly($event->boundedContext);
            $uses[$ns . '\\' . $ctx . '\\Domain\\Event\\' . Str::studly($event->aggregate) . 'Events'] = true;
        }

        return array_keys($uses);
    }

    private function eventConst(Event $event): string
    {
        return strtoupper(Str::snake($event->name));
    }

    private function primaryKeyColumn(ReadModel $readModel): string
    {
        foreach ($readModel->columns as $column) {
            if ($column->isIdentity) {
                return Str::snake($column->name);
            }
        }

        $first = $readModel->columns->fields[0] ?? null;

        return $first !== null ? Str::snake($first->name) : 'id';
    }

    /** @return list<Event> */
    private function projectedEvents(BoundedContext $context, ReadModel $readModel): array
    {
        $events = [];
        if ($readModel->projections !== []) {
            foreach ($readModel->projections as $projection) {
                $aggregate = $this->aggregateOf($context, $projection->aggregate);
                $event = $aggregate?->event($projection->event);
                if ($event !== null) {
                    $events[] = $event;
                }
            }

            return $events;
        }

        foreach ($context->aggregates as $aggregate) {
            foreach ($aggregate->events as $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /** @param list<Event> $events */
    private function anchorEvent(array $events): ?Event
    {
        foreach ($events as $event) {
            if ($event->lifecycle === Lifecycle::Create) {
                return $event;
            }
        }

        return $events[0] ?? null;
    }

    private function aggregateOf(BoundedContext $context, string $name): ?Aggregate
    {
        foreach ($context->aggregates as $aggregate) {
            if ($aggregate->name === $name) {
                return $aggregate;
            }
        }

        return null;
    }

    private function commandOf(Aggregate $aggregate, string $name): ?Command
    {
        foreach ($aggregate->commands as $command) {
            if ($command->name === $name) {
                return $command;
            }
        }

        return null;
    }

    private function q(string $value): string
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }

    /**
     * @param list<string> $uses
     * @param list<string> $body
     */
    private function phpFile(string $namespace, array $uses, array $body): string
    {
        $uses = array_values(array_unique($uses));
        sort($uses);

        $lines = ['<?php', '', 'declare(strict_types=1);', '', 'namespace ' . $namespace . ';', ''];
        foreach ($uses as $use) {
            $lines[] = 'use ' . $use . ';';
        }
        if ($uses !== []) {
            $lines[] = '';
        }
        $lines = array_merge($lines, $body);

        return implode("\n", $lines) . "\n";
    }
}
