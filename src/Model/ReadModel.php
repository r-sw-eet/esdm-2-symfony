<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

final class ReadModel
{
    /** @param list<Projection> $projections */
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public readonly string $boundedContext,
        public readonly ?string $paradigm,
        public readonly Schema $columns,
        public readonly array $projections,
    ) {
    }

    public function projectsEvent(string $event): bool
    {
        foreach ($this->projections as $projection) {
            if ($projection->event === $event) {
                return true;
            }
        }

        return false;
    }
}
