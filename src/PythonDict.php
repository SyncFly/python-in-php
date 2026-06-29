<?php

namespace Python_In_PHP;

use ArrayAccess;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * PHP wrapper for Python dicts that have integer keys or are empty.
 * These cases are ambiguous after JSON round-trip because PHP json_encode
 * would serialize them as a JSON array, indistinguishable from a Python list.
 *
 * Implements JsonSerializable so json_encode emits the __python_type__ protocol
 * wrapper, letting the Python side reconstruct a dict with correct key types.
 */
class PythonDict implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function jsonSerialize(): mixed
    {
        // Use stdClass so json_encode emits {} (JSON object) instead of [] (JSON array),
        // even when all keys are integers.
        $obj = new \stdClass();
        foreach ($this->data as $k => $v) {
            $key = (string) $k;
            $obj->{$key} = $this->serializeValue($v);
        }
        return ['__python_type__' => 'dict', 'value' => $obj];
    }

    private function serializeValue(mixed $v): mixed
    {
        if ($v instanceof self) {
            return $v->jsonSerialize();
        }
        if (is_array($v)) {
            return array_map(fn($item) => $this->serializeValue($item), $v);
        }
        return $v;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->data);
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
