<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Adapter;

use Esdm\Generator\Adapter\SymfonyEventSourcingDb\SymfonyEventSourcingDbAdapter;
use Esdm\Generator\Model\ModelFactory;
use PHPUnit\Framework\TestCase;

final class SymfonyEventSourcingDbAdapterTest extends TestCase
{
    /** @return array<string, string> path => contents */
    private static function generate(): array
    {
        $documents = [
            ['kind' => 'domain', 'name' => 'todo'],
            ['kind' => 'bounded-context', 'name' => 'tasks'],
            [
                'kind' => 'aggregate',
                'name' => 'task',
                'scope' => ['boundedContext' => 'tasks'],
                'identifiedBy' => ['field' => 'task-id'],
                'state' => [
                    'type' => 'object',
                    'properties' => ['task-id' => ['type' => 'string'], 'title' => ['type' => 'string']],
                    'required' => ['task-id', 'title'],
                ],
            ],
            [
                'kind' => 'command',
                'name' => 'add-task',
                'scope' => ['boundedContext' => 'tasks', 'aggregate' => 'task'],
                'data' => ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']],
                'publishes' => ['task-added'],
            ],
            [
                'kind' => 'command',
                'name' => 'delete-task',
                'scope' => ['boundedContext' => 'tasks', 'aggregate' => 'task'],
                'data' => ['type' => 'object', 'properties' => ['task-id' => ['type' => 'string']], 'required' => ['task-id']],
                'publishes' => ['task-deleted'],
            ],
            [
                'kind' => 'event',
                'name' => 'task-added',
                'scope' => ['boundedContext' => 'tasks', 'aggregate' => 'task'],
                'data' => [
                    'type' => 'object',
                    'properties' => ['task-id' => ['type' => 'string'], 'title' => ['type' => 'string']],
                    'required' => ['task-id', 'title'],
                ],
            ],
            [
                'kind' => 'event',
                'name' => 'task-deleted',
                'scope' => ['boundedContext' => 'tasks', 'aggregate' => 'task'],
                'data' => ['type' => 'object', 'properties' => ['task-id' => ['type' => 'string']], 'required' => ['task-id']],
            ],
            [
                'kind' => 'state-machine',
                'name' => 'task-lifecycle',
                'scope' => ['boundedContext' => 'tasks', 'aggregate' => 'task'],
                'initial' => 'open',
                'states' => [['name' => 'open'], ['name' => 'deleted', 'final' => true]],
                'transitions' => [['on' => 'task-deleted', 'to' => 'deleted']],
                'admits' => [['command' => 'delete-task', 'from' => ['open']]],
            ],
            [
                'kind' => 'read-model',
                'name' => 'task-list',
                'scope' => ['boundedContext' => 'tasks'],
                'schema' => [
                    'type' => 'object',
                    'properties' => ['task-id' => ['type' => 'string'], 'title' => ['type' => 'string']],
                    'required' => ['task-id'],
                ],
                'projections' => [
                    ['aggregate' => 'task', 'event' => 'task-added'],
                    ['aggregate' => 'task', 'event' => 'task-deleted'],
                ],
            ],
            [
                'kind' => 'query',
                'name' => 'list-tasks',
                'scope' => ['boundedContext' => 'tasks'],
                'readModel' => 'task-list',
                'parameters' => [],
            ],
        ];

        $model = (new ModelFactory())->create($documents);

        return (new SymfonyEventSourcingDbAdapter())
            ->generate($model, ['namespace' => 'App', 'appName' => 'todo'])
            ->files();
    }

    public function testEmitsTheFullApplicationFileTree(): void
    {
        $files = self::generate();

        $expected = [
            'src/Tasks/Domain/Task.php',
            'src/Tasks/Domain/TaskState.php',
            'src/Tasks/Domain/Event/TaskEvents.php',
            'src/Tasks/Domain/Command/AddTask.php',
            'src/Tasks/Domain/Command/DeleteTask.php',
            'src/Tasks/Application/TaskService.php',
            'src/Tasks/ReadModel/TaskListProjector.php',
            'src/Tasks/ReadModel/TaskListFinder.php',
            'src/Tasks/Api/TasksController.php',
            'src/Shared/EventStore/EventStore.php',
            'src/Shared/EventStore/EsdbClientFactory.php',
            'src/Shared/Console/ObserveCommand.php',
            'src/Shared/ConcurrencyViolation.php',
            'src/Dev/DevController.php',
            'src/Dev/catalog.json',
            'src/Dev/source.bpmn',
            'src/Kernel.php',
            'config/services.yaml',
            'composer.json',
            'compose.yaml',
            'Dockerfile',
            'README.md',
        ];
        foreach ($expected as $path) {
            self::assertArrayHasKey($path, $files, "missing {$path}");
        }
    }

    public function testWireFormatMatchesTheNimbusTargets(): void
    {
        $files = self::generate();

        // subject scheme /<aggregate>/<id> and the { payload, nimbusMeta } envelope
        self::assertStringContainsString("private const SUBJECT_ROOT = '/task';", $files['src/Tasks/Application/TaskService.php']);
        self::assertStringContainsString("'nimbusMeta' => ['correlationid' => \$correlationId],", $files['src/Shared/EventStore/EventStore.php']);
        // CloudEvents type domain.aggregate.event-name
        self::assertStringContainsString("public const TASK_ADDED = 'todo.task.task-added';", $files['src/Tasks/Domain/Event/TaskEvents.php']);
        // create/mutate write preconditions
        self::assertStringContainsString('new IsSubjectPristine($subject)', $files['src/Shared/EventStore/EventStore.php']);
        self::assertStringContainsString('new IsSubjectPopulated($subject)', $files['src/Shared/EventStore/EventStore.php']);
        self::assertStringContainsString('EventStore::PRISTINE', $files['src/Tasks/Application/TaskService.php']);
        self::assertStringContainsString('EventStore::POPULATED', $files['src/Tasks/Application/TaskService.php']);
    }

    public function testDeciderGuardsIllegalTransitionsWhileCreatesAreUnguarded(): void
    {
        $files = self::generate();
        $task = $files['src/Tasks/Domain/Task.php'];

        self::assertStringContainsString("if (!in_array(\$state->status, ['open'], true)) {", $task);
        self::assertStringContainsString("throw new IllegalTransition('delete-task', (\$state->status ?? '') === '' ? 'undefined' : (string) \$state->status);", $task);
        // only the guarded transition throws; the create path carries no guard
        self::assertSame(1, substr_count($task, 'throw new IllegalTransition'));
        self::assertArrayHasKey('src/Shared/IllegalTransition.php', $files);

        // the fold evolves the machine status when the transition event replays
        self::assertStringContainsString("\$this->status = 'deleted';", $files['src/Tasks/Domain/TaskState.php']);
    }

    public function testProjectorTracksRevisionAndFinderStripsBookkeeping(): void
    {
        $files = self::generate();

        self::assertStringContainsString("'revision' => (int) \$event->id,", $files['src/Tasks/ReadModel/TaskListProjector.php']);
        self::assertStringContainsString("private const COLLECTION = 'rm_task_list';", $files['src/Tasks/ReadModel/TaskListProjector.php']);
        self::assertStringContainsString('public function lowerBound(): ?int', $files['src/Tasks/ReadModel/TaskListProjector.php']);
        // the finder rebuilds rows from known columns only - no _id, no revision
        self::assertStringNotContainsString("'revision' =>", $files['src/Tasks/ReadModel/TaskListFinder.php']);
        self::assertStringNotContainsString("'_id' =>", $files['src/Tasks/ReadModel/TaskListFinder.php']);
    }
}
