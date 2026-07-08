<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * One property of a JSON-Schema `object` (an aggregate's `state`, a command's
 * `data`, an event's `data` or a read-model column).
 */
final class Field
{
    public function __construct(
        public readonly string $name,
        public readonly string $jsonType,
        public readonly bool $required,
        public readonly mixed $default,
        public readonly bool $hasDefault,
        public readonly bool $isIdentity = false,
    ) {
    }

    public function withIdentity(bool $isIdentity): self
    {
        return new self($this->name, $this->jsonType, $this->required, $this->default, $this->hasDefault, $isIdentity);
    }
}
