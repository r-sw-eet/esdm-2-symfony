<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/** One reference to an event with concrete data, used in scenario given/then. */
final class EventExample
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly string $event,
        public readonly array $data,
    ) {
    }
}
