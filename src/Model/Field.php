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
        /**
         * An object's `properties` and an array's `items`. Both used to be discarded, which is why
         * FEEL could not bind a path or a collection element - the parser was never the obstacle.
         *
         * @var list<Field>
         */
        public readonly array $nested = [],
        public readonly ?Field $element = null,
    ) {
    }

    /** The field reached by `a.b`, or null when this field has no such property. */
    public function property(string $propertyName): ?Field
    {
        foreach ($this->nested as $field) {
            if ($field->name === $propertyName) {
                return $field;
            }
        }

        return null;
    }

    public function isCollection(): bool
    {
        return $this->jsonType === 'array';
    }

    public function withIdentity(bool $isIdentity): self
    {
        return new self($this->name, $this->jsonType, $this->required, $this->default, $this->hasDefault, $isIdentity);
    }
}
