<?php

declare(strict_types=1);

namespace Esdm\Generator\Tests\Adapter;

use Esdm\Generator\Adapter\SymfonyPatchlevelPostgres\SymfonyPatchlevelPostgresAdapter;
use Esdm\Generator\Model\ModelFactory;
use PHPUnit\Framework\TestCase;

final class SymfonyPatchlevelPostgresAdapterTest extends TestCase
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

        return (new SymfonyPatchlevelPostgresAdapter())
            ->generate($model, ['namespace' => 'App', 'appName' => 'todo'])
            ->files();
    }

    public function testEmitsTheFullApplicationFileTree(): void
    {
        $files = self::generate();

        $expected = [
            'src/Tasks/Domain/Task.php',
            'src/Tasks/Domain/Event/TaskAdded.php',
            'src/Tasks/Domain/Event/TaskDeleted.php',
            'src/Tasks/Domain/Command/AddTask.php',
            'src/Tasks/Domain/Command/DeleteTask.php',
            'src/Tasks/Application/TaskService.php',
            'src/Tasks/ReadModel/TaskListProjector.php',
            'src/Tasks/ReadModel/TaskListFinder.php',
            'src/Tasks/Api/TasksController.php',
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

    public function testAggregateRootTracksStateAndTheMachineStatus(): void
    {
        $task = self::generate()['src/Tasks/Domain/Task.php'];

        self::assertStringContainsString('private string $status;', $task);
        // the final state is applied when its transition event is replayed
        self::assertStringContainsString("\$this->status = 'deleted';", $task);
        // the create factory seeds the aggregate without a guard
        self::assertStringContainsString('public static function addTask(', $task);
    }

    public function testGuardedTransitionRejectsIllegalTransitionsWhileCreatesAreUnguarded(): void
    {
        $files = self::generate();
        $task = $files['src/Tasks/Domain/Task.php'];

        self::assertStringContainsString("if (!in_array(\$this->status, ['open'], true)) {", $task);
        self::assertStringContainsString("throw new IllegalTransition('delete-task', \$this->status);", $task);
        // only the guarded transition throws; the create path carries no guard
        self::assertSame(1, substr_count($task, 'throw new IllegalTransition'));
        self::assertArrayHasKey('src/Shared/IllegalTransition.php', $files);
    }
}
