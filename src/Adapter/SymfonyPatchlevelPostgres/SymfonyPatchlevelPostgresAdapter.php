<?php

declare(strict_types=1);

namespace Esdm\Generator\Adapter\SymfonyPatchlevelPostgres;

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
use Esdm\Generator\Model\Policy;
use Esdm\Generator\Model\Query;
use Esdm\Generator\Model\ReadModel;
use Esdm\Generator\Model\Scenario;
use Esdm\Generator\Support\Str;

/**
 * Emits a runnable, dockerized Symfony application that implements the ESDM
 * model with CQRS + event sourcing on top of patchlevel/event-sourcing and
 * PostgreSQL. Write side: commands -> aggregates -> events (eventstore table).
 * Read side: an async subscription worker projects events into read-model
 * tables that the query API reads.
 */
final class SymfonyPatchlevelPostgresAdapter implements Adapter
{
    public function name(): string
    {
        return 'symfony-patchlevel-postgres';
    }

    public function description(): string
    {
        return 'Symfony 7 + patchlevel/event-sourcing + PostgreSQL (CQRS, event-sourced, async projections).';
    }

    public function slug(): string
    {
        return 'symfony';
    }

    public function generate(Model $model, array $options): GeneratedProject
    {
        $namespace = (string) ($options['namespace'] ?? 'App');
        $appName = (string) ($options['appName'] ?? $model->domain);

        $project = new GeneratedProject();

        foreach ($model->boundedContexts as $context) {
            foreach ($context->aggregates as $aggregate) {
                $this->emitAggregate($project, $namespace, $context, $aggregate);
                $this->emitEvents($project, $namespace, $context, $aggregate);
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
        $this->emitTests($project, $namespace, $model);
        $this->emitDevEndpoint($project, $namespace, $model, $options);
        $this->emitSkeleton($project, $namespace, $appName, $model);

        return $project;
    }

    // ---- 0004 domain-console contract (dev-only surface) -------------------

    /** @param array<string, mixed> $options */
    private function emitDevEndpoint(GeneratedProject $project, string $ns, Model $model, array $options): void
    {
        $project->add('src/Dev/catalog.json', $this->catalogJson($model));
        $project->add('src/Dev/source.bpmn', (string) ($options['bpmnSource'] ?? ''));
        $project->add('src/Dev/DevController.php', $this->phpFile($ns . '\\Dev', [
            'Doctrine\\DBAL\\Connection',
            'Symfony\\Bundle\\FrameworkBundle\\Controller\\AbstractController',
            'Symfony\\Component\\HttpFoundation\\JsonResponse',
            'Symfony\\Component\\HttpFoundation\\Response',
            'Symfony\\Component\\Routing\\Attribute\\Route',
        ], [
            '/**',
            ' * Dev-only window onto the app for an external domain console (0004): the',
            ' * model catalog, the authoring BPMN and the raw event store. Not part of',
            ' * the domain API — never expose it in production.',
            ' */',
            'final class DevController extends AbstractController',
            '{',
            '    public function __construct(private readonly Connection $connection)',
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
            "            \$rows = \$this->connection->fetchAllAssociative('SELECT id, aggregate, aggregate_id, playhead, event, payload, recorded_on FROM eventstore ORDER BY id DESC LIMIT 50');",
            '        } catch (\\Throwable) {',
            '            $rows = [];',
            '        }',
            '',
            '        foreach ($rows as &$row) {',
            "            \$row['payload'] = json_decode((string) \$row['payload'], true);",
            '        }',
            '',
            '        return new JsonResponse($rows);',
            '    }',
            '}',
        ]));
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

    // ---- Policy -> processor (event reacts, dispatches a command) ----------

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
            $eventClass = Str::studly($event->name);
            $cmdClass = Str::studly($command->name);
            $aggClass = Str::studly($emitAgg->name);
            $serviceProp = Str::camel($emitAgg->name) . 'Service';
            $method = Str::camel($command->name);
            $handleIdProp = Str::camel($handleAgg->identityField);
            $targetIdName = Str::camel($policy->handleAggregate) . 'Id'; // e.g. requestId

            // Map the emitted command's fields from the trigger event: the trigger
            // aggregate's identity is exposed as <aggregate>Id, the rest by name.
            $args = [];
            foreach ($command->data as $field) {
                if ($field->name === $targetIdName) {
                    $args[] = '$event->' . $handleIdProp . '->toString()';
                } elseif ($event->data->has($field->name)) {
                    $f = $event->data->field($field->name);
                    $args[] = $f !== null && $f->isIdentity
                        ? '$event->' . Str::camel($field->name) . '->toString()'
                        : '$event->' . Str::camel($field->name);
                } else {
                    $args[] = "''";
                }
            }

            $uses = [
                $ns . '\\' . $handleCtx . '\\Domain\\Event\\' . $eventClass,
                $ns . '\\' . $emitCtx . '\\Application\\' . $aggClass . 'Service',
                $ns . '\\' . $emitCtx . '\\Domain\\Command\\' . $cmdClass,
                'Patchlevel\\EventSourcing\\Attribute\\Processor',
                'Patchlevel\\EventSourcing\\Attribute\\Subscribe',
            ];

            $lines = [
                '#[Processor(' . $this->q(Str::snake($policy->name)) . ')]',
                'final class ' . $class,
                '{',
                '    public function __construct(private readonly ' . $aggClass . 'Service $' . $serviceProp . ')',
                '    {',
                '    }',
                '',
                '    #[Subscribe(' . $eventClass . '::class)]',
                '    public function on' . $eventClass . '(' . $eventClass . ' $event): void',
                '    {',
                '        $this->' . $serviceProp . '->' . $method . '(new ' . $cmdClass . '(' . implode(', ', $args) . '));',
                '    }',
                '}',
            ];

            $project->add('src/' . $emitCtx . '/Policy/' . $class . '.php', $this->phpFile($policyNs, $uses, $lines));
        }
    }

    // ---- GWT scenarios -> PHPUnit aggregate tests --------------------------

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
                'Patchlevel\\EventSourcing\\Aggregate\\Uuid',
                'Patchlevel\\EventSourcing\\PhpUnit\\Test\\AggregateRootTestCase',
            ];
            foreach ($aggregate->events as $event) {
                $uses[] = $domainNs . '\\Event\\' . Str::studly($event->name);
            }
            foreach ($feature->scenarios as $scenario) {
                if ($scenario->isRejection()) {
                    $uses[] = $ns . '\\Shared\\DomainViolation';
                    break;
                }
            }

            $lines = [
                'final class ' . $class . ' extends AggregateRootTestCase',
                '{',
                '    protected function aggregateClass(): string',
                '    {',
                '        return ' . $aggClass . '::class;',
                '    }',
            ];

            foreach ($feature->scenarios as $scenario) {
                $lines[] = '';
                $lines = array_merge($lines, $this->scenarioMethod($aggregate, $scenario));
            }

            $lines[] = '}';

            $project->add(
                'tests/' . $ctx . '/' . $class . '.php',
                $this->phpFile($testNs, $uses, $lines),
            );
        }
    }

    /** @return list<string> */
    private function scenarioMethod(Aggregate $aggregate, Scenario $scenario): array
    {
        $method = 'test' . Str::studly($scenario->name);
        $aggClass = Str::studly($aggregate->name);

        $command = $this->commandOf($aggregate, $scenario->commandName);
        $event = $command !== null ? $aggregate->event((string) $command->primaryEvent()) : null;
        $given = array_map(fn (EventExample $e): string => $this->eventInstance($aggregate, $e), $scenario->given);

        // A rejection scenario asserts a guard/transition refuses the command.
        // AggregateRootTestCase captures the thrown exception via its own fluent
        // ->expectsException() (the when() closure does not let it propagate).
        if ($scenario->isRejection()) {
            $lines = [
                '    public function ' . $method . '(): void',
                '    {',
                '        // ' . ($scenario->rejectionReason ?? 'rejected'),
                '        $this',
            ];
            if ($given !== []) {
                $lines[] = '            ->given(' . implode(', ', $given) . ')';
            }
            $lines[] = '            ->when(' . $this->whenClosure($aggregate, $command, $event, $scenario, $aggClass) . ')';
            $lines[] = '            ->expectsException(DomainViolation::class);';
            $lines[] = '    }';

            return $lines;
        }

        $then = array_map(fn (EventExample $e): string => $this->eventInstance($aggregate, $e), $scenario->thenEvents);

        $lines = ['    public function ' . $method . '(): void', '    {', '        $this'];

        if ($given !== []) {
            $lines[] = '            ->given(' . implode(', ', $given) . ')';
        }

        $lines[] = '            ->when(' . $this->whenClosure($aggregate, $command, $event, $scenario, $aggClass) . ')';
        $lines[] = '            ->then(' . implode(', ', $then) . ');';
        $lines[] = '    }';

        return $lines;
    }

    private function whenClosure(Aggregate $aggregate, ?Command $command, ?Event $event, Scenario $scenario, string $aggClass): string
    {
        if ($command === null || $event === null) {
            return 'static fn () => null';
        }

        $factory = Str::camel($command->name);

        if ($command->lifecycle === Lifecycle::Create) {
            // A create command (e.g. add-task) may not carry the id — the server mints it.
            // For a deterministic test, take the event fields from the expected `then` event.
            $source = $this->createEventData($scenario, $event);
            $args = [];
            foreach ($event->data as $field) {
                if ($field->name !== $aggregate->identityField && !$command->data->has($field->name)) {
                    continue; // filled by the aggregate (default/state), not a factory argument
                }
                $args[] = $this->scalarOrUuid($field, $source[$field->name] ?? ($scenario->commandData[$field->name] ?? null));
            }

            return 'static fn () => ' . $aggClass . '::' . $factory . '(' . implode(', ', $args) . ')';
        }

        $args = [];
        foreach ($event->data as $field) {
            if ($field->name === $aggregate->identityField || !$command->data->has($field->name)) {
                continue;
            }
            $args[] = $this->scalarOrUuid($field, $scenario->commandData[$field->name] ?? null);
        }

        // A temporal guard adds a clock parameter; pass a fixed far-future value
        // so it never masks the transition/state guard the scenario is testing.
        $guard = $this->commandGuard($aggregate, $command);
        if ($guard['usesToday']) {
            $args[] = "'2099-01-01'";
        }
        if ($guard['usesNow']) {
            $args[] = "'2099-01-01T00:00:00+00:00'";
        }

        return 'static fn (' . $aggClass . ' $aggregate) => $aggregate->' . $factory . '(' . implode(', ', $args) . ')';
    }

    private function eventInstance(Aggregate $aggregate, EventExample $example): string
    {
        $event = $aggregate->event($example->event);
        if ($event === null) {
            return 'null';
        }

        $args = [];
        foreach ($event->data as $field) {
            $args[] = $this->scalarOrUuid($field, $example->data[$field->name] ?? null);
        }

        return 'new ' . Str::studly($event->name) . '(' . implode(', ', $args) . ')';
    }

    private function scalarOrUuid(Field $field, mixed $value): string
    {
        if ($field->isIdentity) {
            return 'Uuid::fromString(' . $this->q((string) $value) . ')';
        }

        return var_export($value, true);
    }

    /** @return array<string, mixed> */
    private function createEventData(Scenario $scenario, Event $event): array
    {
        foreach ($scenario->thenEvents as $example) {
            if ($example->event === $event->name) {
                return $example->data;
            }
        }

        return [];
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

    /**
     * A command's precondition: the states it is admissible from (0001) and an
     * optional compiled FEEL predicate (0002).
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
            $compiled = Feel::compile(Feel::parse($admit->when), static fn (string $n): string => '$this->' . Str::camel($n));
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
            $lines[] = '        if (!in_array($this->status, [' . $list . '], true)) {';
            $lines[] = '            throw new IllegalTransition(' . $this->q($command->name) . ', $this->status);';
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

    // ---- Domain: aggregate -------------------------------------------------

    private function emitAggregate(GeneratedProject $project, string $ns, BoundedContext $context, Aggregate $aggregate): void
    {
        $ctx = Str::studly($context->name);
        $class = Str::studly($aggregate->name);
        $idProp = Str::camel($aggregate->identityField);
        $domainNs = $ns . '\\' . $ctx . '\\Domain';

        $uses = [
            'Patchlevel\\EventSourcing\\Aggregate\\BasicAggregateRoot',
            'Patchlevel\\EventSourcing\\Aggregate\\Uuid',
            'Patchlevel\\EventSourcing\\Attribute\\Aggregate',
            'Patchlevel\\EventSourcing\\Attribute\\Apply',
            'Patchlevel\\EventSourcing\\Attribute\\Id',
        ];
        foreach ($aggregate->events as $event) {
            $uses[] = $domainNs . '\\Event\\' . Str::studly($event->name);
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

        $lines = [];
        $lines[] = '#[Aggregate(' . $this->q($aggregate->name) . ')]';
        $lines[] = 'final class ' . $class . ' extends BasicAggregateRoot';
        $lines[] = '{';
        $lines[] = '    #[Id]';
        $lines[] = '    private Uuid $' . $idProp . ';';
        foreach ($aggregate->nonIdentityState() as $field) {
            $lines[] = '    private ' . Types::scalarPhpType($field) . ' $' . Str::camel($field->name) . ';';
        }
        if ($aggregate->stateMachine !== null) {
            $lines[] = '    private string $status;';
        }

        foreach ($aggregate->commands as $command) {
            $lines[] = '';
            $lines = array_merge($lines, $this->aggregateMethod($aggregate, $command, $idProp));
        }

        foreach ($aggregate->events as $event) {
            $lines[] = '';
            $lines = array_merge($lines, $this->applyMethod($aggregate, $event, $idProp));
        }

        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/Domain/' . $class . '.php',
            $this->phpFile($domainNs, $uses, $lines),
        );
    }

    /** @return list<string> */
    private function aggregateMethod(Aggregate $aggregate, Command $command, string $idProp): array
    {
        $event = $aggregate->event((string) $command->primaryEvent());
        if ($event === null) {
            return [];
        }

        $method = Str::camel($command->name);
        $eventClass = Str::studly($event->name);
        $nonIdParams = [];
        $ctorArgs = [];

        // Event fields come from: the identity, a command field of the same name,
        // or (when the command does not carry it) a snapshot of aggregate state.
        foreach ($event->data as $field) {
            $name = $field->name;
            $camel = Str::camel($name);
            if ($name === $aggregate->identityField) {
                $ctorArgs[] = $command->lifecycle === Lifecycle::Create ? '$' . $idProp : '$this->' . $idProp;
            } elseif ($command->data->has($name)) {
                $nonIdParams[] = Types::scalarPhpType($field) . ' $' . $camel;
                $ctorArgs[] = '$' . $camel;
            } elseif ($aggregate->state->has($name)) {
                $ctorArgs[] = '$this->' . $camel;
            } else {
                $ctorArgs[] = Types::defaultLiteral($field);
            }
        }

        $ctorList = implode(', ', $ctorArgs);

        if ($command->lifecycle === Lifecycle::Create) {
            $params = array_merge(['Uuid $' . $idProp], $nonIdParams);

            return [
                '    public static function ' . $method . '(' . implode(', ', $params) . '): self',
                '    {',
                '        $self = new self();',
                '        $self->recordThat(new ' . $eventClass . '(' . $ctorList . '));',
                '',
                '        return $self;',
                '    }',
            ];
        }

        $guard = $this->commandGuard($aggregate, $command);
        $params = array_merge($nonIdParams, $this->clockParams($guard));

        $body = ['    public function ' . $method . '(' . implode(', ', $params) . '): void', '    {'];
        $body = array_merge($body, $this->guardStatements($command, $guard));
        $body[] = '        $this->recordThat(new ' . $eventClass . '(' . $ctorList . '));';
        $body[] = '    }';

        return $body;
    }

    /** @return list<string> */
    private function applyMethod(Aggregate $aggregate, Event $event, string $idProp): array
    {
        $eventClass = Str::studly($event->name);

        $body = [];
        if ($event->lifecycle === Lifecycle::Delete) {
            $body[] = '        // soft delete: the projection removes the row, the event stream stays intact';
        } elseif ($event->lifecycle === Lifecycle::Create) {
            foreach ($aggregate->state as $field) {
                $prop = Str::camel($field->name);
                if ($field->name === $aggregate->identityField || $event->data->has($field->name)) {
                    $body[] = '        $this->' . $prop . ' = $event->' . $prop . ';';
                } else {
                    $body[] = '        $this->' . $prop . ' = ' . Types::defaultLiteral($field) . ';';
                }
            }
        } else {
            foreach ($event->data as $field) {
                if ($field->name === $aggregate->identityField || !$aggregate->state->has($field->name)) {
                    continue;
                }
                $prop = Str::camel($field->name);
                $body[] = '        $this->' . $prop . ' = $event->' . $prop . ';';
            }
        }

        // state machine evolve: an event that has a transition advances $status.
        if ($aggregate->stateMachine !== null) {
            $target = $aggregate->stateMachine->transitionTarget($event->name);
            if ($target !== null) {
                $body[] = '        $this->status = ' . $this->q($target) . ';';
            }
        }

        $lines = [
            '    #[Apply]',
            '    protected function apply' . $eventClass . '(' . $eventClass . ' $event): void',
            '    {',
        ];
        $lines = array_merge($lines, $body);
        $lines[] = '    }';

        return $lines;
    }

    // ---- Domain: events ----------------------------------------------------

    private function emitEvents(GeneratedProject $project, string $ns, BoundedContext $context, Aggregate $aggregate): void
    {
        $ctx = Str::studly($context->name);
        $eventNs = $ns . '\\' . $ctx . '\\Domain\\Event';

        foreach ($aggregate->events as $event) {
            $class = Str::studly($event->name);
            $params = [];
            foreach ($event->data as $field) {
                $params[] = '        public readonly ' . Types::phpType($field, true) . ' $' . Str::camel($field->name) . ',';
            }

            $lines = [
                '#[Event(' . $this->q($event->type) . ')]',
                'final class ' . $class,
                '{',
                '    public function __construct(',
            ];
            $lines = array_merge($lines, $params);
            $lines[] = '    ) {';
            $lines[] = '    }';
            $lines[] = '}';

            $project->add(
                'src/' . $ctx . '/Domain/Event/' . $class . '.php',
                $this->phpFile($eventNs, [
                    'Patchlevel\\EventSourcing\\Aggregate\\Uuid',
                    'Patchlevel\\EventSourcing\\Attribute\\Event',
                ], $lines),
            );
        }
    }

    // ---- Domain: command DTOs ---------------------------------------------

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

    // ---- Application service ----------------------------------------------

    private function emitApplicationService(GeneratedProject $project, string $ns, BoundedContext $context, Aggregate $aggregate): void
    {
        $ctx = Str::studly($context->name);
        $appNs = $ns . '\\' . $ctx . '\\Application';
        $domainNs = $ns . '\\' . $ctx . '\\Domain';
        $aggClass = Str::studly($aggregate->name);
        $idProp = Str::camel($aggregate->identityField);

        $uses = [
            $domainNs . '\\' . $aggClass,
            'Patchlevel\\EventSourcing\\Aggregate\\Uuid',
            'Patchlevel\\EventSourcing\\Repository\\Repository',
            'Patchlevel\\EventSourcing\\Repository\\RepositoryManager',
        ];
        foreach ($aggregate->commands as $command) {
            $uses[] = $domainNs . '\\Command\\' . Str::studly($command->name);
        }

        $lines = [
            'final class ' . $aggClass . 'Service',
            '{',
            '    /** @var Repository<' . $aggClass . '> */',
            '    private Repository $repository;',
            '',
            '    public function __construct(RepositoryManager $repositoryManager)',
            '    {',
            '        $this->repository = $repositoryManager->get(' . $aggClass . '::class);',
            '    }',
        ];

        foreach ($aggregate->commands as $command) {
            $lines[] = '';
            $lines = array_merge($lines, $this->serviceMethod($aggregate, $command, $aggClass, $idProp));
        }

        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/Application/' . $aggClass . 'Service.php',
            $this->phpFile($appNs, $uses, $lines),
        );
    }

    /** @return list<string> */
    private function serviceMethod(Aggregate $aggregate, Command $command, string $aggClass, string $idProp): array
    {
        $event = $aggregate->event((string) $command->primaryEvent());
        $method = Str::camel($command->name);
        $cmdClass = Str::studly($command->name);

        // Pass exactly the fields the command carries; state-snapshot fields are
        // filled by the aggregate itself, so they are not method arguments.
        $eventArgs = [];
        if ($event !== null) {
            foreach ($event->data as $field) {
                if ($field->name === $aggregate->identityField || !$command->data->has($field->name)) {
                    continue;
                }
                $eventArgs[] = '$command->' . Str::camel($field->name);
            }
        }

        if ($command->lifecycle === Lifecycle::Create) {
            $callArgs = array_merge(['$id'], $eventArgs);
            // A caller-assigned identity makes the create idempotent (a re-issued id hits
            // AggregateAlreadyExists); an absent or empty identity field falls back to minting.
            $mint = $command->data->has($aggregate->identityField)
                ? '$command->' . $idProp . " === '' ? Uuid::generate() : Uuid::fromString(\$command->" . $idProp . ')'
                : 'Uuid::generate()';

            return [
                '    public function ' . $method . '(' . $cmdClass . ' $command): string',
                '    {',
                '        $id = ' . $mint . ';',
                '        $aggregate = ' . $aggClass . '::' . $method . '(' . implode(', ', $callArgs) . ');',
                '        $this->repository->save($aggregate);',
                '',
                '        return $id->toString();',
                '    }',
            ];
        }

        // A guard using today()/now() needs the current date/time passed in.
        $guard = $this->commandGuard($aggregate, $command);
        $callArgs = $eventArgs;
        if ($guard['usesToday']) {
            $callArgs[] = "(new \\DateTimeImmutable())->format('Y-m-d')";
        }
        if ($guard['usesNow']) {
            $callArgs[] = "(new \\DateTimeImmutable())->format('Y-m-d\\TH:i:sP')";
        }

        return [
            '    public function ' . $method . '(' . $cmdClass . ' $command): void',
            '    {',
            '        $aggregate = $this->repository->load(Uuid::fromString($command->' . $idProp . '));',
            '        $aggregate->' . $method . '(' . implode(', ', $callArgs) . ');',
            '        $this->repository->save($aggregate);',
            '    }',
        ];
    }

    // ---- Read model: projector --------------------------------------------

    private function emitProjector(GeneratedProject $project, string $ns, BoundedContext $context, ReadModel $readModel): void
    {
        $ctx = Str::studly($context->name);
        $rmNs = $ns . '\\' . $ctx . '\\ReadModel';
        $domainNs = $ns . '\\' . $ctx . '\\Domain';
        $class = Str::studly($readModel->name) . 'Projector';
        $table = 'rm_' . Str::snake($readModel->name);
        $projectorId = Str::snake($readModel->name) . '_1';
        $pk = $this->primaryKeyColumn($readModel);

        $events = $this->projectedEvents($context, $readModel);

        $uses = [
            'Doctrine\\DBAL\\Connection',
            'Doctrine\\DBAL\\ParameterType',
            'Patchlevel\\EventSourcing\\Attribute\\Projector',
            'Patchlevel\\EventSourcing\\Attribute\\Setup',
            'Patchlevel\\EventSourcing\\Attribute\\Subscribe',
            'Patchlevel\\EventSourcing\\Attribute\\Teardown',
        ];
        foreach ($events as $event) {
            $uses[] = $domainNs . '\\Event\\' . Str::studly($event->name);
        }

        $lines = [
            '#[Projector(' . $this->q($projectorId) . ')]',
            'final class ' . $class,
            '{',
            '    private const TABLE = ' . $this->q($table) . ';',
            '',
            '    public function __construct(private readonly Connection $connection)',
            '    {',
            '    }',
            '',
            '    #[Setup]',
            '    public function setup(): void',
            '    {',
            '        $this->connection->executeStatement(',
            '            ' . $this->q($this->createTableSql($table, $readModel, $pk)) . ',',
            '        );',
            '    }',
            '',
            '    #[Teardown]',
            '    public function teardown(): void',
            '    {',
            '        $this->connection->executeStatement(\'DROP TABLE IF EXISTS \' . self::TABLE);',
            '    }',
        ];

        $anchor = $this->anchorEvent($events);
        foreach ($events as $event) {
            $lines[] = '';
            $lines = array_merge($lines, $this->projectorHandler($context, $readModel, $event, $pk, $event === $anchor));
        }

        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/ReadModel/' . $class . '.php',
            $this->phpFile($rmNs, $uses, $lines),
        );
    }

    /**
     * The read model's "anchor" event creates rows: its first create-lifecycle
     * event, or — for an archive read model that only reacts to a delete — the
     * first projected event.
     *
     * @param list<Event> $events
     */
    private function anchorEvent(array $events): ?Event
    {
        foreach ($events as $event) {
            if ($event->lifecycle === Lifecycle::Create) {
                return $event;
            }
        }

        return $events[0] ?? null;
    }

    /** @return list<string> */
    private function projectorHandler(BoundedContext $context, ReadModel $readModel, Event $event, string $pk, bool $isAnchor): array
    {
        $eventClass = Str::studly($event->name);
        $aggregate = $this->aggregateOf($context, $event->aggregate);
        $idField = $aggregate?->identityField ?? $pk;
        $idProp = Str::camel($idField);

        $head = [
            '    #[Subscribe(' . $eventClass . '::class)]',
            '    public function on' . $eventClass . '(' . $eventClass . ' $event): void',
            '    {',
        ];

        if (!$isAnchor && $event->lifecycle === Lifecycle::Delete) {
            $head[] = '        $this->connection->delete(self::TABLE, [' . $this->q($pk) . ' => $event->' . $idProp . '->toString()]);';
            $head[] = '    }';

            return $head;
        }

        if ($isAnchor) {
            $assign = [];
            $boolTypes = [];
            foreach ($readModel->columns as $column) {
                $col = Str::snake($column->name);
                $prop = Str::camel($column->name);
                if ($column->name === $idField) {
                    $assign[] = '            ' . $this->q($col) . ' => $event->' . $prop . '->toString(),';
                } elseif ($event->data->has($column->name)) {
                    $assign[] = '            ' . $this->q($col) . ' => $event->' . $prop . ',';
                } else {
                    $default = $column->required || $column->hasDefault ? Types::defaultLiteral($column) : 'null';
                    $assign[] = '            ' . $this->q($col) . ' => ' . $default . ',';
                }
                if (Types::isBoolean($column)) {
                    $boolTypes[] = '            ' . $this->q($col) . ' => ParameterType::BOOLEAN,';
                }
            }

            $head[] = '        $this->connection->insert(self::TABLE, [';
            $head = array_merge($head, $assign);
            if ($boolTypes !== []) {
                $head[] = '        ], [';
                $head = array_merge($head, $boolTypes);
                $head[] = '        ]);';
            } else {
                $head[] = '        ]);';
            }
            $head[] = '    }';

            return $head;
        }

        // mutate: update the columns the event carries (besides identity)
        $assign = [];
        $boolTypes = [];
        foreach ($event->data as $field) {
            if ($field->name === $idField || !$readModel->columns->has($field->name)) {
                continue;
            }
            $col = Str::snake($field->name);
            $assign[] = '            ' . $this->q($col) . ' => $event->' . Str::camel($field->name) . ',';
            $column = $readModel->columns->field($field->name);
            if ($column !== null && Types::isBoolean($column)) {
                $boolTypes[] = '            ' . $this->q($col) . ' => ParameterType::BOOLEAN,';
            }
        }

        $head[] = '        $this->connection->update(self::TABLE, [';
        $head = array_merge($head, $assign);
        $head[] = '        ], [' . $this->q($pk) . ' => $event->' . $idProp . '->toString()]' . ($boolTypes !== [] ? ', [' : ');');
        if ($boolTypes !== []) {
            $head = array_merge($head, $boolTypes);
            $head[] = '        ]);';
        }
        $head[] = '    }';

        return $head;
    }

    // ---- Read model: finder (query side) ----------------------------------

    private function emitFinder(GeneratedProject $project, string $ns, BoundedContext $context, ReadModel $readModel): void
    {
        $ctx = Str::studly($context->name);
        $rmNs = $ns . '\\' . $ctx . '\\ReadModel';
        $class = Str::studly($readModel->name) . 'Finder';
        $table = 'rm_' . Str::snake($readModel->name);
        $pk = $this->primaryKeyColumn($readModel);
        $columns = implode(', ', array_map(fn (Field $f) => Str::snake($f->name), $readModel->columns->fields));

        $boolCasts = [];
        foreach ($readModel->columns as $column) {
            if (Types::isBoolean($column)) {
                $col = Str::snake($column->name);
                $boolCasts[] = '        $row[' . $this->q($col) . '] = in_array($row[' . $this->q($col) . '], [true, \'t\', \'true\', \'1\', 1], true);';
            }
        }

        $lines = [
            'final class ' . $class,
            '{',
            '    private const TABLE = ' . $this->q($table) . ';',
            '',
            '    public function __construct(private readonly Connection $connection)',
            '    {',
            '    }',
            '',
            '    /** @return list<array<string, mixed>> */',
            '    public function all(): array',
            '    {',
            '        $rows = $this->connection->fetchAllAssociative(\'SELECT ' . $columns . ' FROM \' . self::TABLE . \' ORDER BY ' . $pk . '\');',
            '',
            '        return array_map([$this, \'hydrate\'], $rows);',
            '    }',
            '',
            '    /** @return array<string, mixed>|null */',
            '    public function find(string $id): ?array',
            '    {',
            '        $row = $this->connection->fetchAssociative(\'SELECT ' . $columns . ' FROM \' . self::TABLE . \' WHERE ' . $pk . ' = ?\', [$id]);',
            '',
            '        return $row === false ? null : $this->hydrate($row);',
            '    }',
            '',
            '    /**',
            '     * @param array<string, mixed> $row',
            '     * @return array<string, mixed>',
            '     */',
            '    private function hydrate(array $row): array',
            '    {',
        ];
        $lines = array_merge($lines, $boolCasts);
        $lines[] = '        return $row;';
        $lines[] = '    }';
        $lines[] = '}';

        $project->add(
            'src/' . $ctx . '/ReadModel/' . $class . '.php',
            $this->phpFile($rmNs, ['Doctrine\\DBAL\\Connection'], $lines),
        );
    }

    // ---- API controller ----------------------------------------------------

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
            $body = array_merge($body, $this->queryAction($context, $query, $readModel, $finderProp, $base));
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
        $cmdClass = Str::studly($command->name);
        $routeName = $context->name . '_' . Str::snake($command->name);
        $path = $base . '/' . $command->name;

        $args = [];
        foreach ($command->data as $field) {
            $prop = Str::camel($field->name);
            $args[] = $this->castFromPayload($field, $prop);
        }
        $argList = implode(', ', $args);

        $lines = [
            '    #[Route(' . $this->q($path) . ', name: ' . $this->q($routeName) . ', methods: [\'POST\'])]',
            '    public function ' . $method . '(Request $request): JsonResponse',
            '    {',
            '        $data = $this->payload($request);',
        ];

        if ($command->lifecycle === Lifecycle::Create) {
            $call = '$id = $this->' . $serviceProp . '->' . $method . '(new ' . $cmdClass . '(' . $argList . '));';
            $success = 'return new JsonResponse([\'id\' => $id], Response::HTTP_CREATED);';
        } else {
            $call = '$this->' . $serviceProp . '->' . $method . '(new ' . $cmdClass . '(' . $argList . '));';
            $success = 'return new JsonResponse([\'ok\' => true]);';
        }

        $lines[] = '        try {';
        $lines[] = '            ' . $call;
        $lines[] = '';
        $lines[] = '            ' . $success;
        $lines[] = '        } catch (DomainViolation $e) {';
        $lines[] = '            return new JsonResponse([\'error\' => $e->getMessage()], Response::HTTP_CONFLICT);';
        $lines[] = '        }';
        $lines[] = '    }';

        return $lines;
    }

    /** @return list<string> */
    private function queryAction(BoundedContext $context, Query $query, ReadModel $readModel, string $finderProp, string $base): array
    {
        $method = Str::camel($query->name);
        $routeName = $context->name . '_' . Str::snake($query->name);
        $path = $base . '/' . $query->name;

        if ($query->parameters->fields !== []) {
            return [
                '    #[Route(' . $this->q($path) . ', name: ' . $this->q($routeName) . ', methods: [\'GET\'])]',
                '    public function ' . $method . '(Request $request): JsonResponse',
                '    {',
                '        $row = $this->' . $finderProp . '->find((string) $request->query->get(\'id\', \'\'));',
                '',
                '        return $row === null',
                '            ? new JsonResponse([\'error\' => \'not found\'], Response::HTTP_NOT_FOUND)',
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

    private function castFromPayload(Field $field, string $prop): string
    {
        $key = $this->q($field->name);
        // Fields without a model-declared default keep their zero-value fallback.
        $fallback = Types::defaultLiteral($field);

        return match ($field->jsonType) {
            'string' => '(string) ($data[' . $key . '] ?? ' . $fallback . ')',
            'boolean' => '(bool) ($data[' . $key . '] ?? ' . $fallback . ')',
            'integer' => '(int) ($data[' . $key . '] ?? ' . $fallback . ')',
            'number' => '(float) ($data[' . $key . '] ?? ' . $fallback . ')',
            default => '$data[' . $key . '] ?? null',
        };
    }

    // ---- Skeleton (framework + docker wiring) ------------------------------

    private function emitSkeleton(GeneratedProject $project, string $ns, string $appName, Model $model): void
    {
        $eventDirs = [];
        $projectorIds = [];
        foreach ($model->boundedContexts as $context) {
            $ctx = Str::studly($context->name);
            foreach ($context->aggregates as $aggregate) {
                if ($aggregate->events !== []) {
                    $eventDirs[] = '%kernel.project_dir%/src/' . $ctx . '/Domain/Event';
                }
            }
            foreach ($context->readModels as $readModel) {
                $projectorIds[] = $ns . '\\' . $ctx . '\\ReadModel\\' . Str::studly($readModel->name) . 'Projector';
            }
        }
        $eventDirs = array_values(array_unique($eventDirs));

        $policyIds = [];
        foreach ($model->policies as $policy) {
            $policyIds[] = $ns . '\\' . Str::studly($policy->emitContext) . '\\Policy\\' . Str::studly($policy->name) . 'Policy';
        }

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
            '}',
        ]));
        $project->add('src/Shared/IllegalTransition.php', $this->phpFile($ns . '\\Shared', [], [
            '/** A command was issued from a state the aggregate does not admit it from (0001). */',
            'final class IllegalTransition extends DomainViolation',
            '{',
            '    public function __construct(public readonly string $command, public readonly string $state)',
            '    {',
            "        parent::__construct(sprintf('\"%s\" is not allowed while \"%s\"', \$command, \$state));",
            '    }',
            '}',
        ]));
        $project->add('src/Shared/GuardViolation.php', $this->phpFile($ns . '\\Shared', [], [
            '/** A command precondition (FEEL guard) was not met (0002). */',
            'final class GuardViolation extends DomainViolation',
            '{',
            '    public function __construct(public readonly string $command, public readonly string $requirement)',
            '    {',
            "        parent::__construct(sprintf('\"%s\" requires: %s', \$command, \$requirement));",
            '    }',
            '}',
        ]));
        $project->add('src/Shared/Http/CorsSubscriber.php', $this->corsSubscriber($ns));
        $project->add('src/Shared/Dbal/ConnectionFactory.php', $this->connectionFactory());
        $project->add('config/bundles.php', $this->bundles());
        $project->add('config/packages/framework.yaml', $this->frameworkYaml());
        $project->add('config/routes.yaml', $this->routesYaml());
        $project->add('config/services.yaml', $this->servicesYaml($eventDirs, $projectorIds, $policyIds));
        $project->add('phpunit.xml.dist', $this->phpunitXml());
        $project->add('README.md', $this->appReadme($appName));
    }

    private function composerJson(string $appName): string
    {
        $name = preg_replace('/[^a-z0-9-]+/', '-', strtolower($appName)) ?? 'app';

        return <<<JSON
        {
            "name": "esdm2symfony/{$name}-app",
            "description": "Generated by esdm2symfony (symfony-patchlevel-postgres target). Do not edit by hand; regenerate from the ESDM model.",
            "type": "project",
            "require": {
                "php": ">=8.2",
                "ext-pdo": "*",
                "doctrine/dbal": "^4.4",
                "nyholm/psr7": "^1.8",
                "open-telemetry/exporter-otlp": "^1.0",
                "open-telemetry/opentelemetry-auto-symfony": "^1.0",
                "open-telemetry/sdk": "^1.0",
                "patchlevel/event-sourcing": "^3.4",
                "php-http/discovery": "^1.19",
                "symfony/console": "^7.0",
                "symfony/dotenv": "^7.0",
                "symfony/framework-bundle": "^7.0",
                "symfony/http-client": "^7.0",
                "symfony/runtime": "^7.0",
                "symfony/yaml": "^7.0"
            },
            "require-dev": {
                "patchlevel/event-sourcing-phpunit": "^1.5",
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

        # opentelemetry ext is inert unless OTEL_PHP_AUTOLOAD_ENABLED=true — zero-code, off by default.
        RUN apt-get update \
            && apt-get install -y --no-install-recommends libpq-dev unzip git $PHPIZE_DEPS \
            && docker-php-ext-install pdo_pgsql \
            && pecl install opentelemetry \
            && docker-php-ext-enable opentelemetry \
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
        # Generated stack: db (PostgreSQL), api (write/read HTTP), app (async projection worker).
        services:
          db:
            image: postgres:16-alpine
            environment:
              POSTGRES_USER: app
              POSTGRES_PASSWORD: app
              POSTGRES_DB: app
            ports:
              - "5433:5432"
            volumes:
              - db-data:/var/lib/postgresql/data
            healthcheck:
              test: ["CMD-SHELL", "pg_isready -U app -d app"]
              interval: 3s
              timeout: 3s
              retries: 20

          api:
            build: .
            environment:
              APP_ENV: dev
              APP_DEBUG: "1"
              DATABASE_URL: "pdo-pgsql://app:app@db:5432/app?serverVersion=16&charset=utf8"
              OTEL_PHP_AUTOLOAD_ENABLED: "${OTEL_PHP_AUTOLOAD_ENABLED:-false}"
              OTEL_SERVICE_NAME: "${OTEL_SERVICE_NAME:-__APP_NAME__}"
              OTEL_EXPORTER_OTLP_ENDPOINT: "${OTEL_EXPORTER_OTLP_ENDPOINT:-}"
              OTEL_EXPORTER_OTLP_PROTOCOL: "${OTEL_EXPORTER_OTLP_PROTOCOL:-http/protobuf}"
              OTEL_TRACES_EXPORTER: "${OTEL_TRACES_EXPORTER:-otlp}"
              OTEL_METRICS_EXPORTER: "${OTEL_METRICS_EXPORTER:-none}"
              OTEL_LOGS_EXPORTER: "${OTEL_LOGS_EXPORTER:-none}"
            depends_on:
              db:
                condition: service_healthy
            ports:
              - "8080:8000"

          app:
            build: .
            environment:
              APP_ENV: dev
              APP_DEBUG: "1"
              DATABASE_URL: "pdo-pgsql://app:app@db:5432/app?serverVersion=16&charset=utf8"
              OTEL_PHP_AUTOLOAD_ENABLED: "${OTEL_PHP_AUTOLOAD_ENABLED:-false}"
              OTEL_SERVICE_NAME: "${OTEL_SERVICE_NAME:-__APP_NAME__}"
              OTEL_EXPORTER_OTLP_ENDPOINT: "${OTEL_EXPORTER_OTLP_ENDPOINT:-}"
              OTEL_EXPORTER_OTLP_PROTOCOL: "${OTEL_EXPORTER_OTLP_PROTOCOL:-http/protobuf}"
              OTEL_TRACES_EXPORTER: "${OTEL_TRACES_EXPORTER:-otlp}"
              OTEL_METRICS_EXPORTER: "${OTEL_METRICS_EXPORTER:-none}"
              OTEL_LOGS_EXPORTER: "${OTEL_LOGS_EXPORTER:-none}"
            depends_on:
              db:
                condition: service_healthy
            command:
              - sh
              - -c
              - |
                php bin/console event-sourcing:schema:create || true
                php bin/console event-sourcing:subscription:setup || true
                php bin/console event-sourcing:subscription:boot || true
                while true; do php bin/console event-sourcing:subscription:run || true; sleep 1; done

        # Domain console: this stack serves the 0004 dev contract (/_dev/*) — point the
        # esdm-vue-reader viewer at http://localhost:8080 for commands / read models / events.

        volumes:
          db-data:

        YAML;

        return str_replace('__APP_NAME__', $appName, $yaml);
    }

    private function dotEnv(string $appName): string
    {
        return <<<ENV
        APP_ENV=dev
        APP_DEBUG=1
        APP_SECRET=2f1c0d9e8b7a6f5e4d3c2b1a09f8e7d6
        DATABASE_URL="pdo-pgsql://app:app@db:5432/app?serverVersion=16&charset=utf8"

        # OpenTelemetry (zero-code, via the opentelemetry PHP extension). Off until you flip
        # AUTOLOAD to true and point the endpoint at a collector (e.g. http://lgtm:4318).
        OTEL_PHP_AUTOLOAD_ENABLED=false
        OTEL_SERVICE_NAME={$appName}
        OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
        OTEL_TRACES_EXPORTER=otlp
        OTEL_METRICS_EXPORTER=none
        OTEL_LOGS_EXPORTER=none
        # OTEL_EXPORTER_OTLP_ENDPOINT=http://lgtm:4318

        ENV;
    }

    private function makefile(): string
    {
        return <<<'MAKE'
        .PHONY: up down setup logs api-logs

        up:
        	docker compose up -d --build

        down:
        	docker compose down -v

        setup:
        	docker compose exec api php bin/console event-sourcing:schema:create
        	docker compose exec api php bin/console event-sourcing:subscription:setup
        	docker compose exec api php bin/console event-sourcing:subscription:boot

        logs:
        	docker compose logs -f

        api-logs:
        	docker compose logs -f api

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

    private function connectionFactory(): string
    {
        return <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Shared\Dbal;

        use Doctrine\DBAL\Connection;
        use Doctrine\DBAL\DriverManager;
        use Doctrine\DBAL\Tools\DsnParser;

        /**
         * Builds a DBAL connection from a DATABASE_URL. The event store and the
         * projections deliberately use separate connection instances: PostgreSQL
         * cannot run projector DDL inside the event-store write transaction.
         */
        final class ConnectionFactory
        {
            public function create(string $dsn): Connection
            {
                $params = (new DsnParser(['postgres' => 'pdo_pgsql', 'postgresql' => 'pdo_pgsql']))->parse($dsn);

                return DriverManager::getConnection($params);
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

    /**
     * @param list<string> $eventDirs
     * @param list<string> $projectorIds
     * @param list<string> $policyIds
     */
    private function servicesYaml(array $eventDirs, array $projectorIds, array $policyIds = []): string
    {
        $eventPaths = implode(', ', array_map(static fn (string $d) => "'" . $d . "'", $eventDirs));
        $subscriberRefs = implode(', ', array_map(static fn (string $id) => "'@" . $id . "'", array_merge($projectorIds, $policyIds)));
        $projectorOverrides = '';
        foreach ($projectorIds as $id) {
            $projectorOverrides .= "    {$id}:\n        arguments: ['@projection.connection']\n\n";
        }

        return <<<YAML
        # Generated wiring for patchlevel/event-sourcing on PostgreSQL.
        # Two DBAL connections: eventstore (writes) and projection (read-model DDL).
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

            App\\Shared\\Dbal\\ConnectionFactory: ~

            eventstore.connection:
                class: Doctrine\\DBAL\\Connection
                factory: ['@App\\Shared\\Dbal\\ConnectionFactory', 'create']
                arguments: ['%env(DATABASE_URL)%']

            projection.connection:
                class: Doctrine\\DBAL\\Connection
                factory: ['@App\\Shared\\Dbal\\ConnectionFactory', 'create']
                arguments: ['%env(DATABASE_URL)%']

            Doctrine\\DBAL\\Connection: '@eventstore.connection'

            Patchlevel\\EventSourcing\\Serializer\\EventSerializer:
                factory: ['Patchlevel\\EventSourcing\\Serializer\\DefaultEventSerializer', 'createFromPaths']
                arguments: [[{$eventPaths}]]

            Patchlevel\\EventSourcing\\Metadata\\AggregateRoot\\AttributeAggregateRootRegistryFactory: ~

            Patchlevel\\EventSourcing\\Metadata\\AggregateRoot\\AggregateRootRegistry:
                factory: ['@Patchlevel\\EventSourcing\\Metadata\\AggregateRoot\\AttributeAggregateRootRegistryFactory', 'create']
                arguments:
                    - ['%kernel.project_dir%/src']

            Patchlevel\\EventSourcing\\Store\\Store:
                class: Patchlevel\\EventSourcing\\Store\\DoctrineDbalStore
                arguments:
                    - '@eventstore.connection'
                    - '@Patchlevel\\EventSourcing\\Serializer\\EventSerializer'

            Patchlevel\\EventSourcing\\Subscription\\Store\\SubscriptionStore:
                class: Patchlevel\\EventSourcing\\Subscription\\Store\\DoctrineSubscriptionStore
                arguments: ['@eventstore.connection']

        {$projectorOverrides}    Patchlevel\\EventSourcing\\Subscription\\Subscriber\\MetadataSubscriberAccessorRepository:
                arguments: [[{$subscriberRefs}]]

            Patchlevel\\EventSourcing\\Subscription\\Engine\\SubscriptionEngine:
                class: Patchlevel\\EventSourcing\\Subscription\\Engine\\DefaultSubscriptionEngine
                arguments:
                    - '@Patchlevel\\EventSourcing\\Store\\Store'
                    - '@Patchlevel\\EventSourcing\\Subscription\\Store\\SubscriptionStore'
                    - '@Patchlevel\\EventSourcing\\Subscription\\Subscriber\\MetadataSubscriberAccessorRepository'

            Patchlevel\\EventSourcing\\Repository\\RepositoryManager:
                class: Patchlevel\\EventSourcing\\Repository\\DefaultRepositoryManager
                arguments:
                    - '@Patchlevel\\EventSourcing\\Metadata\\AggregateRoot\\AggregateRootRegistry'
                    - '@Patchlevel\\EventSourcing\\Store\\Store'

            Patchlevel\\EventSourcing\\Schema\\SchemaConfigurator:
                class: Patchlevel\\EventSourcing\\Schema\\ChainDoctrineSchemaConfigurator
                arguments:
                    - ['@Patchlevel\\EventSourcing\\Store\\Store', '@Patchlevel\\EventSourcing\\Subscription\\Store\\SubscriptionStore']

            Patchlevel\\EventSourcing\\Schema\\SchemaDirector:
                class: Patchlevel\\EventSourcing\\Schema\\DoctrineSchemaDirector
                arguments:
                    - '@eventstore.connection'
                    - '@Patchlevel\\EventSourcing\\Schema\\SchemaConfigurator'

            Patchlevel\\EventSourcing\\Console\\Command\\SchemaCreateCommand:
                arguments: ['@Patchlevel\\EventSourcing\\Schema\\SchemaDirector']
                tags: ['console.command']

            Patchlevel\\EventSourcing\\Console\\Command\\SchemaUpdateCommand:
                arguments: ['@Patchlevel\\EventSourcing\\Schema\\SchemaDirector']
                tags: ['console.command']

            Patchlevel\\EventSourcing\\Console\\Command\\SubscriptionSetupCommand:
                arguments: ['@Patchlevel\\EventSourcing\\Subscription\\Engine\\SubscriptionEngine']
                tags: ['console.command']

            Patchlevel\\EventSourcing\\Console\\Command\\SubscriptionBootCommand:
                arguments: ['@Patchlevel\\EventSourcing\\Subscription\\Engine\\SubscriptionEngine']
                tags: ['console.command']

            Patchlevel\\EventSourcing\\Console\\Command\\SubscriptionRunCommand:
                arguments:
                    - '@Patchlevel\\EventSourcing\\Subscription\\Engine\\SubscriptionEngine'
                    - '@Patchlevel\\EventSourcing\\Store\\Store'
                tags: ['console.command']

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
        `symfony-patchlevel-postgres` target. Do not edit it by hand — change the
        ESDM model and regenerate.

        ## Architecture

        - **Write side** (`api`): HTTP `POST /<context>/<command>` builds a command,
          an application service loads/creates the aggregate, the aggregate records
          domain **events**, and the repository appends them to the PostgreSQL event
          store (`eventstore` table).
        - **Read side** (`app` worker): the patchlevel subscription engine consumes
          events asynchronously and updates read-model tables (`rm_*`).
        - **Query side** (`api`): HTTP `GET /<context>/<query>` reads the read-model
          tables. Reads are eventually consistent with writes.

        ## Run

        ```sh
        docker compose up -d --build
        # the app worker creates the schema and starts projecting automatically
        curl -s -XPOST localhost:8080/<context>/<create-command> -d '{...}'
        curl -s localhost:8080/<context>/<list-query>
        ```

        ## Domain console

        The app serves the **domain-console contract** (esdm-extensions 0004) in dev:
        `GET /_dev/catalog` (model catalog), `GET /_dev/bpmn` (authoring diagram) and
        `GET /_dev/events` (newest slice of the event store), plus permissive CORS. Point the
        stack-agnostic **esdm-vue-reader** viewer at `http://localhost:8080` to send commands,
        watch events and see read models update. The `/_dev/*` surface is a dev window — do
        not expose it in production.

        ## Extending the application

        Everything here is derived from the ESDM model — never edit generated code by
        hand. To change behavior, change the **model** and regenerate:

        - New behavior on the write side → add or extend **commands** and **events**
          (plus state-machine transitions and FEEL guards).
        - Reactions ("whenever X happened, do Y") → model a **policy**; it is
          generated as a subscriber that issues the follow-up command.
        - Different views of the data → add or extend **read models**.

        Integrations that leave the system (brokers, mail, external APIs) subscribe
        to the event store downstream instead of hooking into generated code — every
        state change is already an event, so consumers need nothing from this app but
        the stream.

        MD;
    }

    // ---- 0004 catalog (the app's self-description for the console) ---------

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
                    // Use the snake_case DB column name — that is the key the finder returns.
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

    // ---- Helpers -----------------------------------------------------------

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

    private function createTableSql(string $table, ReadModel $readModel, string $pk): string
    {
        $columns = [];
        foreach ($readModel->columns as $column) {
            $sql = Str::snake($column->name) . ' ' . Types::pgColumn($column);
            $sql .= $column->required || $column->isIdentity ? ' NOT NULL' : '';
            if ($column->hasDefault) {
                $sql .= ' DEFAULT ' . $this->sqlDefault($column);
            }
            $columns[] = $sql;
        }
        $columns[] = 'PRIMARY KEY (' . $pk . ')';

        return 'CREATE TABLE IF NOT EXISTS ' . $table . ' (' . implode(', ', $columns) . ')';
    }

    private function sqlDefault(Field $field): string
    {
        if ($field->jsonType === 'boolean') {
            return $field->default ? 'TRUE' : 'FALSE';
        }
        if (in_array($field->jsonType, ['integer', 'number'], true)) {
            return (string) $field->default;
        }

        return "'" . str_replace("'", "''", (string) $field->default) . "'";
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

    private function aggregateOf(BoundedContext $context, string $name): ?Aggregate
    {
        foreach ($context->aggregates as $aggregate) {
            if ($aggregate->name === $name) {
                return $aggregate;
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
