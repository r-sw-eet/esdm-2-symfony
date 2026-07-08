<?php

declare(strict_types=1);

namespace Esdm\Generator\Console;

use Esdm\Generator\Bpmn\BpmnParser;
use Esdm\Generator\Bpmn\BpmnToEsdm;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * `esdmgen bpmn:map <app-dir>` — proposal 0003. Reads BPMN authored under the
 * app's `authoring/` directory and emits ESDM (core + 0001 + 0002) into its
 * `model/` directory, ready for `esdmgen generate`. BPMN/DMN is the human
 * source of truth; ESDM is the generated intermediate representation.
 */
#[AsCommand('bpmn:map', 'Map a BPMN model to ESDM (proposal 0003).')]
final class BpmnMapCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('app-dir', InputArgument::OPTIONAL, 'Directory containing esdmgen.yaml', '.')
            ->addOption('authoring', 'a', InputOption::VALUE_REQUIRED, 'BPMN source directory', 'authoring')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'ESDM output directory', 'model')
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED, 'Fallback domain name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $appDir = rtrim((string) $input->getArgument('app-dir'), '/');

        $config = [];
        if (is_file($appDir . '/esdmgen.yaml')) {
            $config = (array) Yaml::parseFile($appDir . '/esdmgen.yaml');
        }
        $fallbackDomain = (string) ($input->getOption('domain')
            ?? $config['options']['appName']
            ?? basename(realpath($appDir) ?: $appDir));

        $authoringDir = $appDir . '/' . (string) $input->getOption('authoring');
        $modelDir = $appDir . '/' . (string) $input->getOption('model');

        $bpmnFiles = $this->findBpmn($authoringDir);
        if ($bpmnFiles === []) {
            $io->error(sprintf('No .bpmn files found under %s.', $authoringDir));

            return Command::FAILURE;
        }

        $io->section(sprintf('Mapping %d BPMN file(s) to ESDM', count($bpmnFiles)));

        // Parse every file and merge their processes into one model.
        $parser = new BpmnParser();
        $combined = ['domain' => null, 'processes' => [], 'messageFlows' => [], 'unmapped' => []];
        foreach ($bpmnFiles as $file) {
            $parsed = $parser->parse((string) file_get_contents($file));
            $combined['domain'] ??= $parsed['domain'];
            $combined['processes'] = array_merge($combined['processes'], $parsed['processes']);
            $combined['messageFlows'] = array_merge($combined['messageFlows'], $parsed['messageFlows'] ?? []);
            $combined['unmapped'] = array_merge($combined['unmapped'], $parsed['unmapped']);
            $io->writeln(sprintf('  <info>%s</info> — %d process(es)', basename($file), count($parsed['processes'])));
        }

        $result = (new BpmnToEsdm())->map($combined, $fallbackDomain);

        if (!is_dir($modelDir) && !mkdir($modelDir, 0o775, true) && !is_dir($modelDir)) {
            $io->error('Could not create model directory: ' . $modelDir);

            return Command::FAILURE;
        }

        $corePath = $modelDir . '/' . $result['domain'] . '.esdm.yaml';
        file_put_contents($corePath, $this->dumpDocuments($result['documents']));
        $io->writeln(sprintf('  wrote <info>%s</info> (%d documents)', $corePath, count($result['documents'])));

        foreach ($result['stateMachines'] as $machine) {
            $aggregate = (string) ($machine['_aggregate'] ?? $machine['name']);
            unset($machine['_aggregate']);
            $path = $modelDir . '/' . $aggregate . '.statemachine.yaml';
            file_put_contents($path, $this->dumpDocuments([$machine]));
            $io->writeln(sprintf('  wrote <info>%s</info>', $path));
        }

        foreach ($result['notes'] as $note) {
            $io->warning($note);
        }

        $io->success(sprintf('Mapped to ESDM in %s. Run: esdmgen generate %s', $modelDir, $appDir));

        return Command::SUCCESS;
    }

    /**
     * @param list<array<string, mixed>> $documents
     */
    private function dumpDocuments(array $documents): string
    {
        $blocks = array_map(
            static fn (array $doc): string => rtrim(Yaml::dump($doc, 8, 2, Yaml::DUMP_NULL_AS_TILDE)),
            $documents,
        );

        return implode("\n---\n", $blocks) . "\n";
    }

    /** @return list<string> */
    private function findBpmn(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.bpmn$/', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
