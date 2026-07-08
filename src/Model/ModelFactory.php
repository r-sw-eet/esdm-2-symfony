<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * Turns raw ESDM documents into a resolved {@see Model}: groups by kind, builds
 * typed nodes and wires every cross-reference (command -> event, event ->
 * aggregate, read-model -> events, query -> read-model). This is the "parse +
 * map" stage; it knows nothing about any target framework.
 */
final class ModelFactory
{
    private const LIFECYCLE_ANNOTATION = 'esdm-extensions.io/lifecycle';

    /** @param list<array<string, mixed>> $documents */
    public function create(array $documents): Model
    {
        $byKind = [];
        foreach ($documents as $document) {
            $kind = (string) ($document['kind'] ?? '');
            $byKind[$kind][] = $document;
        }

        $domainName = $this->singleDomainName($byKind['domain'] ?? []);

        /** @var array<string, BoundedContext> $contexts */
        $contexts = [];
        foreach ($byKind['bounded-context'] ?? [] as $document) {
            $name = (string) $document['name'];
            $contexts[$name] = new BoundedContext($name, $domainName);
        }

        $context = static function (string $name) use (&$contexts, $domainName): BoundedContext {
            return $contexts[$name] ??= new BoundedContext($name, $domainName);
        };

        // Aggregates, indexed by "context/aggregate" so events/commands can attach.
        /** @var array<string, Aggregate> $aggregateIndex */
        $aggregateIndex = [];
        foreach ($byKind['aggregate'] ?? [] as $document) {
            $scope = $document['scope'] ?? [];
            $contextName = (string) ($scope['boundedContext'] ?? 'default');
            $identityField = (string) ($document['identifiedBy']['field'] ?? 'id');
            $state = $this->schemaWithIdentity(Schema::fromArray($document['state'] ?? []), $identityField);

            $aggregate = new Aggregate(
                name: (string) $document['name'],
                domain: $domainName,
                boundedContext: $contextName,
                identityField: $identityField,
                state: $state,
            );

            $context($contextName)->aggregates[] = $aggregate;
            $aggregateIndex[$contextName . '/' . $aggregate->name] = $aggregate;
        }

        // Commands first: they tell us which events are create/delete.
        $rawCommands = $byKind['command'] ?? [];
        /** @var array<string, Lifecycle> $eventLifecycle */
        $eventLifecycle = [];
        foreach ($rawCommands as $document) {
            $lifecycle = Lifecycle::fromName((string) $document['name'], $this->annotation($document, self::LIFECYCLE_ANNOTATION));
            foreach ((array) ($document['publishes'] ?? []) as $eventName) {
                $eventLifecycle[(string) $eventName] = $lifecycle;
            }
        }

        // Events.
        foreach ($byKind['event'] ?? [] as $document) {
            $scope = $document['scope'] ?? [];
            $contextName = (string) ($scope['boundedContext'] ?? 'default');
            $aggregateName = (string) ($scope['aggregate'] ?? '');
            $aggregate = $aggregateIndex[$contextName . '/' . $aggregateName] ?? null;
            if ($aggregate === null) {
                continue;
            }

            $name = (string) $document['name'];
            $annotated = $this->annotation($document, self::LIFECYCLE_ANNOTATION);
            $lifecycle = $annotated !== null
                ? Lifecycle::from($annotated)
                : ($eventLifecycle[$name] ?? Lifecycle::Mutate);

            $aggregate->events[] = new Event(
                name: $name,
                domain: $domainName,
                boundedContext: $contextName,
                aggregate: $aggregateName,
                data: $this->schemaWithIdentity(Schema::fromArray($document['data'] ?? []), $aggregate->identityField),
                lifecycle: $lifecycle,
                type: $this->annotation($document, 'cloudevents.type')
                    ?? sprintf('%s.%s.%s', $domainName, $aggregateName, $name),
            );
        }

        // Commands -> aggregates (now that events exist).
        foreach ($rawCommands as $document) {
            $scope = $document['scope'] ?? [];
            $contextName = (string) ($scope['boundedContext'] ?? 'default');
            $aggregateName = (string) ($scope['aggregate'] ?? '');
            $aggregate = $aggregateIndex[$contextName . '/' . $aggregateName] ?? null;
            if ($aggregate === null) {
                continue;
            }

            $publishes = array_map('strval', (array) ($document['publishes'] ?? []));
            $aggregate->commands[] = new Command(
                name: (string) $document['name'],
                domain: $domainName,
                boundedContext: $contextName,
                aggregate: $aggregateName,
                data: Schema::fromArray($document['data'] ?? []),
                publishes: $publishes,
                lifecycle: Lifecycle::fromName((string) $document['name'], $this->annotation($document, self::LIFECYCLE_ANNOTATION)),
            );
        }

        // State machines (extension): attach an aggregate lifecycle.
        foreach ($byKind['state-machine'] ?? [] as $document) {
            $scope = $document['scope'] ?? [];
            $contextName = (string) ($scope['boundedContext'] ?? 'default');
            $aggregateName = (string) ($scope['aggregate'] ?? '');
            $aggregate = $aggregateIndex[$contextName . '/' . $aggregateName] ?? null;
            if ($aggregate === null) {
                continue;
            }

            $states = [];
            foreach ((array) ($document['states'] ?? []) as $state) {
                $states[] = new State((string) ($state['name'] ?? ''), (bool) ($state['final'] ?? false));
            }
            $transitions = [];
            foreach ((array) ($document['transitions'] ?? []) as $transition) {
                $transitions[] = new Transition((string) ($transition['on'] ?? ''), (string) ($transition['to'] ?? ''));
            }
            $admits = [];
            foreach ((array) ($document['admits'] ?? []) as $admit) {
                $admits[] = new Admit(
                    command: (string) ($admit['command'] ?? ''),
                    from: array_map('strval', (array) ($admit['from'] ?? [])),
                    when: isset($admit['when']) ? (string) $admit['when'] : null,
                );
            }

            $aggregate->stateMachine = new StateMachine(
                boundedContext: $contextName,
                aggregate: $aggregateName,
                initial: (string) ($document['initial'] ?? ''),
                states: $states,
                transitions: $transitions,
                admits: $admits,
            );
        }

        // Read models.
        foreach ($byKind['read-model'] ?? [] as $document) {
            $scope = $document['scope'] ?? [];
            $contextName = (string) ($scope['boundedContext'] ?? 'default');
            $projections = [];
            foreach ((array) ($document['projections'] ?? []) as $projection) {
                $projections[] = new Projection(
                    aggregate: (string) ($projection['aggregate'] ?? ''),
                    event: (string) ($projection['event'] ?? ''),
                    rule: isset($projection['rule']) ? (string) $projection['rule'] : null,
                );
            }

            $context($contextName)->readModels[] = new ReadModel(
                name: (string) $document['name'],
                domain: $domainName,
                boundedContext: $contextName,
                paradigm: isset($document['paradigm']) ? (string) $document['paradigm'] : null,
                columns: Schema::fromArray($document['schema'] ?? []),
                projections: $projections,
            );
        }

        // Queries.
        foreach ($byKind['query'] ?? [] as $document) {
            $scope = $document['scope'] ?? [];
            $contextName = (string) ($scope['boundedContext'] ?? 'default');
            $context($contextName)->queries[] = new Query(
                name: (string) $document['name'],
                domain: $domainName,
                boundedContext: $contextName,
                readModel: (string) ($document['readModel'] ?? ''),
                parameters: Schema::fromArray($document['parameters'] ?? []),
            );
        }

        return new Model(
            $domainName,
            array_values($contexts),
            $this->parseFeatures($byKind['feature'] ?? [], $domainName),
            $this->parsePolicies($byKind['policy'] ?? [], $domainName),
        );
    }

    /**
     * @param list<array<string, mixed>> $policyDocs
     * @return list<Policy>
     */
    private function parsePolicies(array $policyDocs, string $domainName): array
    {
        $policies = [];
        foreach ($policyDocs as $document) {
            $handle = ($document['handles'][0] ?? null);
            $emit = ($document['emits'][0] ?? null);
            if (!is_array($handle) || !is_array($emit) || !isset($handle['aggregate'], $emit['aggregate'])) {
                continue; // only aggregate-bound handle/emit are supported for now
            }

            $policies[] = new Policy(
                name: (string) $document['name'],
                domain: $domainName,
                handleContext: (string) ($handle['boundedContext'] ?? 'default'),
                handleAggregate: (string) $handle['aggregate'],
                handleEvent: (string) ($handle['event'] ?? ''),
                emitContext: (string) ($emit['boundedContext'] ?? 'default'),
                emitAggregate: (string) $emit['aggregate'],
                emitCommand: (string) ($emit['command'] ?? ''),
            );
        }

        return $policies;
    }

    /**
     * @param list<array<string, mixed>> $featureDocs
     * @return list<Feature>
     */
    private function parseFeatures(array $featureDocs, string $domainName): array
    {
        $features = [];
        foreach ($featureDocs as $document) {
            $scope = $document['scope'] ?? [];
            if (!isset($scope['aggregate'])) {
                continue; // only the aggregate variant is supported for now
            }

            $scenarios = [];
            foreach ((array) ($document['scenarios'] ?? []) as $raw) {
                $scenarios[] = new Scenario(
                    name: (string) ($raw['name'] ?? ''),
                    given: $this->parseExamples($raw['given'] ?? []),
                    commandName: (string) ($raw['when']['command'] ?? ''),
                    commandData: (array) ($raw['when']['data'] ?? []),
                    thenEvents: $this->parseExamples($raw['then']['events'] ?? []),
                    rejectionReason: isset($raw['then']['rejection'])
                        ? (string) ($raw['then']['rejection']['reason'] ?? 'rejected')
                        : null,
                );
            }

            $features[] = new Feature(
                name: (string) $document['name'],
                domain: $domainName,
                boundedContext: (string) ($scope['boundedContext'] ?? 'default'),
                aggregate: (string) $scope['aggregate'],
                scenarios: $scenarios,
            );
        }

        return $features;
    }

    /**
     * @param mixed $raw
     * @return list<EventExample>
     */
    private function parseExamples(mixed $raw): array
    {
        $examples = [];
        foreach ((array) $raw as $entry) {
            $examples[] = new EventExample(
                event: (string) ($entry['event'] ?? ''),
                data: (array) ($entry['data'] ?? []),
            );
        }

        return $examples;
    }

    /** @param list<array<string, mixed>> $domainDocs */
    private function singleDomainName(array $domainDocs): string
    {
        if ($domainDocs === []) {
            throw new \RuntimeException('Model contains no `domain` document.');
        }

        return (string) $domainDocs[0]['name'];
    }

    private function schemaWithIdentity(Schema $schema, string $identityField): Schema
    {
        $fields = array_map(
            static fn (Field $f): Field => $f->withIdentity($f->name === $identityField),
            $schema->fields,
        );

        return new Schema($fields);
    }

    /** @param array<string, mixed> $document */
    private function annotation(array $document, string $key): ?string
    {
        $value = $document['metadata']['annotations'][$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
