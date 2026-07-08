<?php

declare(strict_types=1);

namespace Esdm\Generator\Console;

use Esdm\Generator\Adapter\AdapterRegistry;
use Esdm\Generator\Feel\Feel;
use Esdm\Generator\Feel\FeelException;
use Esdm\Generator\Lint\EsdmLinter;
use Esdm\Generator\Lint\LintResult;
use Esdm\Generator\Model\DocumentLoader;
use Esdm\Generator\Model\Model;
use Esdm\Generator\Model\ModelFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * `esdmgen generate <app-dir>` — read the app's `esdmgen.yaml`, parse its ESDM
 * model and emit a project with the chosen target adapter.
 */
#[AsCommand('generate', 'Generate a project from an ESDM model.')]
final class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('app-dir', InputArgument::OPTIONAL, 'Directory containing esdmgen.yaml', '.')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Override target adapter')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Override model directory')
            ->addOption('out', 'o', InputOption::VALUE_REQUIRED, 'Override output directory')
            ->addOption('skip-lint', null, InputOption::VALUE_NONE, 'Skip the esdm lint gate (not recommended)')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Treat lint warnings as errors');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $appDir = rtrim((string) $input->getArgument('app-dir'), '/');

        $config = [];
        $configPath = $appDir . '/esdmgen.yaml';
        if (is_file($configPath)) {
            $config = (array) Yaml::parseFile($configPath);
        }

        $target = (string) ($input->getOption('target') ?? $config['target'] ?? '');
        $modelDir = $this->resolve($appDir, (string) ($input->getOption('model') ?? $config['model'] ?? 'model'));
        $outDir = $this->resolve($appDir, (string) ($input->getOption('out') ?? $config['out'] ?? 'generated'));
        $options = (array) ($config['options'] ?? []);

        if ($target === '') {
            $io->error('No target adapter given (set `target:` in esdmgen.yaml or pass --target).');

            return Command::FAILURE;
        }

        $strict = (bool) ($input->getOption('strict') || ($config['lint']['strict'] ?? false));
        if (!$input->getOption('skip-lint') && !$this->lint($io, $modelDir, $strict)) {
            return Command::FAILURE;
        }

        $io->section(sprintf('Generating "%s" from %s', $target, $modelDir));

        $documents = (new DocumentLoader())->loadDirectory($modelDir);
        $model = (new ModelFactory())->create($documents);

        if (!$input->getOption('skip-lint') && !$this->validateFeel($io, $model)) {
            return Command::FAILURE;
        }

        $adapter = AdapterRegistry::withDefaults()->get($target);

        // Each stack writes into its own subdir so multiple targets never collide.
        $outDir = rtrim($outDir, '/') . '/' . $adapter->slug();

        // Embed the app's BPMN (if any) so the console's Author tab can load it.
        $options['bpmnSource'] = $this->readBpmnSource($appDir);

        $project = $adapter->generate($model, $options);
        $project->writeTo($outDir);

        $io->listing(array_keys($project->files()));
        $io->success(sprintf('Wrote %d files to %s', count($project->files()), $outDir));

        return Command::SUCCESS;
    }

    /** The first BPMN file under the app's `authoring/` directory, if present. */
    private function readBpmnSource(string $appDir): string
    {
        $authoring = $appDir . '/authoring';
        if (!is_dir($authoring)) {
            return '';
        }
        $files = glob($authoring . '/*.bpmn') ?: [];
        sort($files);

        return $files === [] ? '' : (string) file_get_contents($files[0]);
    }

    /**
     * Run `esdm lint` as a gate before generation. An invalid model never
     * reaches the adapter — garbage in would only mean garbage out.
     */
    private function lint(SymfonyStyle $io, string $modelDir, bool $strict): bool
    {
        $linter = new EsdmLinter();
        if (!$linter->isAvailable()) {
            $io->error(sprintf('Cannot validate the model: %s', $linter->binaryHint()));

            return false;
        }

        $io->section(sprintf('Linting model in %s', $modelDir));

        try {
            $result = $linter->lint($modelDir);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return false;
        }

        $this->render($io, $result);

        if ($result->hasErrors()) {
            $io->error('Model is not valid ESDM — aborting before generation.');

            return false;
        }

        if ($strict && $result->warnings() !== []) {
            $io->error('Lint warnings present and --strict is set — aborting.');

            return false;
        }

        if ($result->isClean()) {
            $io->writeln('<info>Model passes esdm lint cleanly.</info>');
        }

        return true;
    }

    private function render(SymfonyStyle $io, LintResult $result): void
    {
        foreach ($result->findings as $finding) {
            $io->writeln(sprintf(
                '  <%1$s>%2$s</%1$s> %3$s%4$s [%5$s]',
                $finding->isError() ? 'error' : 'comment',
                $finding->isError() ? 'error' : 'warning',
                $finding->message,
                $finding->location() !== '' ? ' (' . $finding->location() . ')' : '',
                $finding->ruleId,
            ));
        }
    }

    /**
     * Model-aware FEEL gate (proposal 0002): parse every state-machine guard
     * expression and bind its identifiers to real aggregate fields. Runs after
     * parse and before generation, complementing the structural `esdm lint`.
     */
    private function validateFeel(SymfonyStyle $io, Model $model): bool
    {
        $errors = [];
        foreach ($model->aggregates() as $aggregate) {
            if ($aggregate->stateMachine === null) {
                continue;
            }
            $allowed = array_map(static fn ($field) => $field->name, $aggregate->state->fields);
            $allowed[] = 'status';

            foreach ($aggregate->stateMachine->admits as $admit) {
                if ($admit->when === null || $admit->when === '') {
                    continue;
                }
                try {
                    foreach (Feel::validate(Feel::parse($admit->when), $allowed) as $error) {
                        $errors[] = sprintf('%s.when "%s": %s', $admit->command, $admit->when, $error);
                    }
                } catch (FeelException $e) {
                    $errors[] = sprintf('%s.when "%s": %s', $admit->command, $admit->when, $e->getMessage());
                }
            }
        }

        if ($errors === []) {
            return true;
        }

        $io->section('FEEL validation');
        foreach ($errors as $error) {
            $io->writeln('  <error>error</error> ' . $error);
        }
        $io->error('FEEL guard expressions are invalid — aborting before generation.');

        return false;
    }

    private function resolve(string $appDir, string $path): string
    {
        return str_starts_with($path, '/') ? $path : $appDir . '/' . $path;
    }
}
