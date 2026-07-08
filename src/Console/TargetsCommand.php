<?php

declare(strict_types=1);

namespace Esdm\Generator\Console;

use Esdm\Generator\Adapter\AdapterRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('targets', 'List available generation targets.')]
final class TargetsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (name, description, slug).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $adapters = AdapterRegistry::withDefaults()->all();

        if ($input->getOption('json')) {
            $data = [];
            foreach ($adapters as $adapter) {
                $data[] = ['name' => $adapter->name(), 'description' => $adapter->description(), 'slug' => $adapter->slug()];
            }
            $output->writeln(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach ($adapters as $adapter) {
            $rows[] = [$adapter->name(), $adapter->description()];
        }
        $io->table(['Target', 'Description'], $rows);

        return Command::SUCCESS;
    }
}
