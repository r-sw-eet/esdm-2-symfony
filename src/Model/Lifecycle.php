<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * Aggregate-lifecycle role of a command/event. ESDM is descriptive and does not
 * encode this, so it is derived from a `esdm-extensions.io/lifecycle` annotation,
 * falling back to a verb heuristic on the document name.
 */
enum Lifecycle: string
{
    case Create = 'create';
    case Mutate = 'mutate';
    case Delete = 'delete';

    public static function fromName(string $name, ?string $annotation): self
    {
        if ($annotation !== null) {
            return self::from($annotation);
        }

        $verb = preg_split('/[-_]/', $name)[0] ?? '';

        return match (true) {
            in_array($verb, ['add', 'create', 'register', 'open', 'start', 'new', 'init', 'submit', 'draft', 'place', 'raise', 'issue', 'request'], true) => self::Create,
            in_array($verb, ['delete', 'remove', 'archive', 'close', 'cancel', 'discard', 'withdraw'], true) => self::Delete,
            default => self::Mutate,
        };
    }
}
