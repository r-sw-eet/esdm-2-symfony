<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * A Given-When-Then scenario (ESDM GWT extension, aggregate variant): replay
 * `given` events, apply the `when` command, expect either `then` events or a
 * rejection.
 */
final class Scenario
{
    /**
     * @param list<EventExample>   $given
     * @param array<string, mixed> $commandData
     * @param list<EventExample>   $thenEvents
     */
    public function __construct(
        public readonly string $name,
        public readonly array $given,
        public readonly string $commandName,
        public readonly array $commandData,
        public readonly array $thenEvents,
        public readonly ?string $rejectionReason,
    ) {
    }

    public function isRejection(): bool
    {
        return $this->rejectionReason !== null;
    }
}
