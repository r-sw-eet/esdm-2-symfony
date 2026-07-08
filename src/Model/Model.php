<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * The resolved, framework-agnostic ESDM model: a domain with its bounded
 * contexts and the aggregates/events/commands/read-models/queries inside them,
 * with all cross-references already wired. Adapters consume this; they never
 * touch raw YAML.
 */
final class Model
{
    /**
     * @param list<BoundedContext> $boundedContexts
     * @param list<Feature>        $features GWT scenarios (extension docs)
     * @param list<Policy>         $policies event→command reactions
     */
    public function __construct(
        public readonly string $domain,
        public readonly array $boundedContexts,
        public readonly array $features = [],
        public readonly array $policies = [],
    ) {
    }

    public function aggregate(string $boundedContext, string $name): ?Aggregate
    {
        foreach ($this->boundedContexts as $context) {
            if ($context->name !== $boundedContext) {
                continue;
            }
            foreach ($context->aggregates as $aggregate) {
                if ($aggregate->name === $name) {
                    return $aggregate;
                }
            }
        }

        return null;
    }

    /** @return list<Aggregate> */
    public function aggregates(): array
    {
        $aggregates = [];
        foreach ($this->boundedContexts as $context) {
            foreach ($context->aggregates as $aggregate) {
                $aggregates[] = $aggregate;
            }
        }

        return $aggregates;
    }
}
