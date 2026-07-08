<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/** decide: a command is admissible from these states, optionally under a FEEL guard. */
final class Admit
{
    /** @param list<string> $from */
    public function __construct(
        public readonly string $command,
        public readonly array $from,
        public readonly ?string $when,
    ) {
    }
}
