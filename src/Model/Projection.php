<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/** One entry of a read-model's `projections`: which event feeds the read model. */
final class Projection
{
    public function __construct(
        public readonly string $aggregate,
        public readonly string $event,
        public readonly ?string $rule,
    ) {
    }
}
