<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

final class Command
{
    /** @param list<string> $publishes */
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public readonly string $boundedContext,
        public readonly string $aggregate,
        public readonly Schema $data,
        public readonly array $publishes,
        public readonly Lifecycle $lifecycle,
    ) {
    }

    public function primaryEvent(): ?string
    {
        return $this->publishes[0] ?? null;
    }
}
