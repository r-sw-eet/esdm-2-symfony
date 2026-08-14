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
            $fields[] = self::buildField((string) $name, $definition, in_array($name, $required, true));
        }

        return new self($fields);
    }

    /** Keeps an object's own properties and an array's element, which FEEL needs to bind against. */
    private static function buildField(string $name, mixed $definition, bool $required): Field
    {
        $definition = is_array($definition) ? $definition : [];
        $type = (string) ($definition['type'] ?? 'mixed');
        $nested = [];
        $element = null;

        if ($type === 'object') {
            $innerRequired = $definition['required'] ?? [];
            foreach ($definition['properties'] ?? [] as $innerName => $innerDefinition) {
                $nested[] = self::buildField(
                    (string) $innerName,
                    $innerDefinition,
                    in_array($innerName, $innerRequired, true),
                );
            }
        }
        if ($type === 'array' && isset($definition['items'])) {
            $element = self::buildField('item', $definition['items'], true);
        }

        return new Field(
            name: $name,
            jsonType: $type,
            required: $required,
            default: $definition['default'] ?? null,
            hasDefault: array_key_exists('default', $definition),
            isIdentity: false,
            nested: $nested,
            element: $element,
        );
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
