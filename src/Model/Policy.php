<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * An ESDM `policy`: a stateless reaction that emits a command when an event
 * occurs — the cross-aggregate, often cross-context glue of an event-driven
 * system. Modeled here as a single handled event → single emitted command
 * (the common case); both ends reference core documents by name.
 */
final class Policy
{
    public function __construct(
        public readonly string $name,
        public readonly string $domain,
        public readonly string $handleContext,
        public readonly string $handleAggregate,
        public readonly string $handleEvent,
        public readonly string $emitContext,
        public readonly string $emitAggregate,
        public readonly string $emitCommand,
    ) {
    }
}
