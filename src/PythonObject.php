<?php

namespace Python_In_PHP;

use ArrayAccess;
use Countable;
use Exception;
use IteratorAggregate;
use Traversable;

/**
 * A class for working with Python objects as PHP objects
 */
class PythonObject implements ArrayAccess, Countable, IteratorAggregate
{
    private $bridge;
    private $objId;
    private $objType;
    private $isCallable;
    private $isAsync;
    private $methods;
    private $attributes = [];
    private $arrayCache = [];
    private $isGenerator = false;
    private $parentObject = null;
    private $iterator = null;

    function __construct(PythonBridge $bridge, array $ref)
    {
        $this->bridge = $bridge;
        $this->objId = $ref['obj_id'];
        $this->objType = $ref['obj_type'];
        $this->isCallable = $ref['is_callable'] ?? false;
        $this->isAsync = $ref['is_async'] ?? false;
        $this->methods = $ref['methods'] ?? [];
        $this->isGenerator = $ref['is_generator'] ?? false;
    }

    /**
     * Magic method for calling object methods
     */
    function __call($name, $arguments)
    {
        [$args, $kwargs] = $this->classifyArguments($arguments);

        try {
            return $this->bridge->callMethod($this->objId, $name, $args, $kwargs);
        }
        catch (Exception $e) {
            throw new Exception("Error calling method $name: " . $e->getMessage());
        }
    }

    /**
     * Magic method for getting object properties
     */
    function __get($name)
    {
        if (!isset($this->attributes[$name])) {
            try {
                $this->attributes[$name] = $this->bridge->getAttribute($this->objId, $name);
            } catch (Exception $e) {
                throw new Exception("Error getting attribute $name: " . $e->getMessage());
            }
        }
        return $this->attributes[$name];
    }

    /**
     * Magic method for setting object properties
     */
    function __set($name, $value)
    {
        $this->bridge->callMethod($this->objId, '__setattr__', [$name, $value]);
        $this->attributes[$name] = $value;
    }

    /**
     * Magic method for checking property existence
     */
    function __isset($name)
    {
        try {
            $this->__get($name);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Magic method for calling the object as a function
     */
    public function __invoke(...$arguments)
    {
        if (!$this->isCallable) {
            throw new Exception("Object is not callable");
        }

        [$args, $kwargs] = $this->classifyArguments($arguments);

        if ($this->isAsync) {
            return $this->bridge->asyncCallObject($this->objId, $args, $kwargs);
        }

        try {
            return $this->bridge->callObject($this->objId, $args, $kwargs);
        } catch (Exception $e) {
            throw new Exception("Error calling object: " . $e->getMessage());
        }
    }

    /**
     * Convert the object to a string
     */
    public function __toString()
    {
        return $this->bridge->objectToString($this->objId);
    }

    /**
     * @param $arguments
     * @return array
     */
    public function classifyArguments($arguments): array
    {
        $args = [];
        $kwargs = [];
        foreach ($arguments as $key => $argument) {
            if (is_int($key)) $args[] = $argument;
            else $kwargs[$key] = $argument;
        }
        return array($args, $kwargs);
    }

    /**
     * Get information about a method
     */
    private function getMethodInfo($name)
    {
        foreach ($this->methods as $method) {
            if ($method['name'] === $name) {
                return $method;
            }
        }
        return null;
    }

    /**
     * ArrayAccess implementation for working with the object as an array
     */
    public function offsetExists($offset): bool
    {
        try {
            $this->offsetGet($offset);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    public function offsetGet($offset): mixed
    {
        if (!isset($this->arrayCache[$offset])) {
            try {
                $this->arrayCache[$offset] = $this->bridge->callMethod($this->objId, '__getitem__', [$offset]);
            } catch (Exception $e) {
                throw new Exception("Error getting item at offset $offset: " . $e->getMessage());
            }
        }
        return $this->arrayCache[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        $this->bridge->callMethod($this->objId, '__setitem__', [$offset, $value]);
        $this->arrayCache[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        $this->bridge->callMethod($this->objId, '__delitem__', [$offset]);
        unset($this->arrayCache[$offset]);
    }

    /**
     * Countable implementation for counting elements
     */
    public function count(): int
    {
        try {
            return $this->bridge->callMethod($this->objId, '__len__');
        }
        catch (Exception $e) {
            return $this->bridge->callMethod($this->objId, 'count');
        }
    }

    /**
     * IteratorAggregate implementation for iterating over the object
     */
    public function getIterator(): Traversable
    {
        if ($this->iterator === null) {
            return new \ArrayIterator($this->bridge->call('list', [$this]));
        }

        return $this->iterator;
    }

    /**
     * Get the Python object ID
     */
    public function getObjectId(): string
    {
        return $this->objId;
    }

    /**
     * Get the Python object type
     */
    public function getObjectType(): string
    {
        return $this->objType;
    }

    /**
     * Check whether the object is callable
     */
    public function isCallable(): bool
    {
        return $this->isCallable;
    }

    /**
     * Check whether the object is asynchronous
     */
    public function isAsync(): bool
    {
        return $this->isAsync;
    }

    /**
     * Check whether the object is a generator
     */
    public function isGenerator(): bool
    {
        return $this->isGenerator;
    }

    /**
     * Get the list of available methods
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Set the parent object
     */
    public function setParentObject(PythonObject $parent)
    {
        $this->parentObject = $parent;
    }

    /**
     * Get the parent object
     */
    public function getParentObject(): ?PythonObject
    {
        return $this->parentObject;
    }

    /**
     * Serialize the object for passing to Python
     */
    public function __serialize(): array
    {
        return [
            '__python_ref__' => true,
            'obj_id' => $this->objId,
            'obj_type' => $this->objType,
            'is_callable' => $this->isCallable,
            'is_async' => $this->isAsync,
            'methods' => $this->methods,
            'is_generator' => $this->isGenerator
        ];
    }

    /**
     * Convert the object to an array for passing to Python
     */
    public function toArray(): array
    {
        return $this->__serialize();
    }

    /**
     * Destructor — automatically releases the object
     */
    function __destruct()
    {
        try {
            $this->bridge->releaseObject($this->objId);
        }
        catch (Exception $e) {
            // Ignore errors when releasing in the destructor
        }
    }
}