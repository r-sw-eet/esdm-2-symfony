<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * Aggregate lifecycle (proposal 0001): states + transitions (evolve) + admits
 * (decide). `admits[].when` carries an optional FEEL predicate (proposal 0002).
 */
final class StateMachine
{
    /**
     * @param list<State>      $states
     * @param list<Transition> $transitions
     * @param list<Admit>      $admits
     */
    public function __construct(
        public readonly string $boundedContext,
        public readonly string $aggregate,
        public readonly string $initial,
        public readonly array $states,
        public readonly array $transitions,
        public readonly array $admits,
    ) {
    }

    /** Target state for an event, or null if the event is state-neutral. */
    public function transitionTarget(string $event): ?string
    {
        foreach ($this->transitions as $transition) {
            if ($transition->event === $event) {
                return $transition->to;
            }
        }

        return null;
    }

    public function admitFor(string $command): ?Admit
    {
        foreach ($this->admits as $admit) {
            if ($admit->command === $command) {
                return $admit;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function stateNames(): array
    {
        return array_map(static fn (State $s): string => $s->name, $this->states);
    }
}
