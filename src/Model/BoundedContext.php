<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

final class BoundedContext
{
    /**
     * @param list<Aggregate> $aggregates
     * @param list<ReadModel> $readModels
     * @param list<Query>     $queries
     */
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public array $aggregates = [],
        public array $readModels = [],
        public array $queries = [],
    ) {
    }

    public function readModel(string $name): ?ReadModel
    {
        foreach ($this->readModels as $readModel) {
            if ($readModel->name === $name) {
                return $readModel;
            }
        }

        return null;
    }
}
