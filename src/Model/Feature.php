<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * A GWT `feature` document (aggregate variant): a set of scenarios about one
 * aggregate. Extension documents never enter the core cross-reference graph;
 * they are resolved against it by name at emit time.
 */
final class Feature
{
    /** @param list<Scenario> $scenarios */
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public readonly string $boundedContext,
        public readonly string $aggregate,
        public readonly array $scenarios,
    ) {
    }
}
