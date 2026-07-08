<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/** evolve: an event moves the machine to a state. */
final class Transition
{
    public function __construct(
        public readonly string $event,
        public readonly string $to,
    ) {
    }
}
