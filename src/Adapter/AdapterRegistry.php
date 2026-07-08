<?php

declare(strict_types=1);

namespace Esdm\Generator\Adapter;

use Esdm\Generator\Adapter\SymfonyPatchlevelPostgres\SymfonyPatchlevelPostgresAdapter;

final class AdapterRegistry
{
    /** @var array<string, Adapter> */
    private array $adapters = [];

    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register(new SymfonyPatchlevelPostgresAdapter());

        return $registry;
    }

    public function register(Adapter $adapter): void
    {
        $this->adapters[$adapter->name()] = $adapter;
    }

    public function get(string $name): Adapter
    {
        return $this->adapters[$name]
            ?? throw new \RuntimeException(sprintf(
                'Unknown target "%s". Available: %s.',
                $name,
                implode(', ', array_keys($this->adapters)),
            ));
    }

    /** @return list<Adapter> */
    public function all(): array
    {
        return array_values($this->adapters);
    }
}
