<?php

declare(strict_types=1);

namespace Esdm\Generator\Console;

use Esdm\Generator\Adapter\AdapterRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('targets', 'List available generation targets.')]
final class TargetsCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];
        foreach (AdapterRegistry::withDefaults()->all() as $adapter) {
            $rows[] = [$adapter->name(), $adapter->description()];
        }
        $io->table(['Target', 'Description'], $rows);

        return Command::SUCCESS;
    }
}
