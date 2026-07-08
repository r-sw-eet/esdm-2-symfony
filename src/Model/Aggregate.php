<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

final class Aggregate
{
    /**
     * @param list<Event>   $events
     * @param list<Command> $commands
     */
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public readonly string $boundedContext,
        public readonly string $identityField,
        public readonly Schema $state,
        public array $events = [],
        public array $commands = [],
        public ?StateMachine $stateMachine = null,
    ) {
    }

    /** State fields excluding the identity field. */
    public function nonIdentityState(): array
    {
        return array_values(array_filter(
            $this->state->fields,
            fn (Field $f): bool => $f->name !== $this->identityField,
        ));
    }

    public function event(string $name): ?Event
    {
        foreach ($this->events as $event) {
            if ($event->name === $name) {
                return $event;
            }
        }

        return null;
    }

    public function createEvent(): ?Event
    {
        foreach ($this->events as $event) {
            if ($event->lifecycle === Lifecycle::Create) {
                return $event;
            }
        }

        return $this->events[0] ?? null;
    }
}
