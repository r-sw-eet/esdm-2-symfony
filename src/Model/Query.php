<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

final class Query
{
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public readonly string $boundedContext,
        public readonly string $readModel,
        public readonly Schema $parameters,
    ) {
    }
}
