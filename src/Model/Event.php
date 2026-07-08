<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

final class Event
{
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public readonly string $boundedContext,
        public readonly string $aggregate,
        public readonly Schema $data,
        public readonly Lifecycle $lifecycle,
        public readonly string $type,
    ) {
    }
}
