<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

final class State
{
    public function __construct(
        public readonly string $name,
        public readonly bool $final,
    ) {
    }
}
