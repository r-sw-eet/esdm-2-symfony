<?php

declare(strict_types=1);

namespace Esdm\Generator\Model;

/**
 * A parsed JSON-Schema `object` ({type, properties, required}). ESDM uses these
 * for aggregate state, command/event data and read-model rows.
 *
 * @implements \IteratorAggregate<int, Field>
 */
final class Schema implements \IteratorAggregate
{
    /** @param list<Field> $fields */
    public function __construct(public readonly array $fields)
    {
    }

    /** @param array<string, mixed> $raw a JSON-schema object node */
    public static function fromArray(array $raw): self
    {
        $properties = $raw['properties'] ?? [];
        $required = $raw['required'] ?? [];
        $fields = [];

        foreach ($properties as $name => $definition) {
            $definition = is_array($definition) ? $definition : [];
            $fields[] = new Field(
                name: (string) $name,
                jsonType: (string) ($definition['type'] ?? 'mixed'),
                required: in_array($name, $required, true),
                default: $definition['default'] ?? null,
                hasDefault: array_key_exists('default', $definition),
            );
        }

        return new self($fields);
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    public function has(string $name): bool
    {
        return $this->field($name) !== null;
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->fields);
    }
}
