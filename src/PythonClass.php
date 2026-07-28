<?php

namespace Python_In_PHP;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Nette\PhpGenerator\Helpers;
use py\sys;
use ReflectionClass;
use ReflectionProperty;
use Traversable;

class PythonClass implements ArrayAccess, Countable, IteratorAggregate
{
    // Value-type constructors (list, str, int…) come back converted to a native PHP value.
    private mixed $python_obj;

    function __construct(...$args)
    {
        $this->python_obj = self::constructPythonObject(...$args);
    }

    private static function getCalledClass(): array
    {
        $name = get_called_class();
        $path = explode('\\', $name);
        array_shift($path);

        $module = $command = implode('.', array_map(self::unsanitizeName(...), $path));

        return [$name, $module, $command];
    }

    /** Reverse the '_' prefix the stub generator adds to PHP-keyword names (Python `list` -> class `_list`). */
    private static function unsanitizeName(string $part): string
    {
        $stripped = substr($part, 1);
        if (str_starts_with($part, '_') && isset(Helpers::Keywords[strtolower($stripped)])) {
            return $stripped;
        }
        return $part;
    }

    static function init()
    {
        $python_bridge = PythonBridge::startOrGetRunning();

        [$php_class, $module, $command] = self::getCalledClass();

        $python_bridge->importModule($module);

        $ref = new ReflectionClass($php_class);
        $staticProps = $ref->getProperties(ReflectionProperty::IS_STATIC);

        foreach ($staticProps as $property) {
            try {
                $name = $property->getName();
                $new_value = $python_bridge->eval($command)->$name;
                $php_class::$$name = $new_value;
            }
            catch (\Exception $e) {

            }
        }
    }

    /** The wrapped PythonObject, or a clear error when the value was converted to a native type. */
    private function requirePythonObject(string $operation): PythonObject
    {
        if ($this->python_obj instanceof PythonObject) return $this->python_obj;
        throw new \BadMethodCallException(
            static::class . "::$operation is unavailable: the wrapped value is a native "
            . get_debug_type($this->python_obj) . "; use toArray(), a cast, iteration or array access"
        );
    }

    /** The wrapped value as a PHP array. */
    public function toArray(): array
    {
        if (is_array($this->python_obj)) return $this->python_obj;
        if (is_object($this->python_obj) && method_exists($this->python_obj, 'toArray')) return $this->python_obj->toArray();
        return (array)$this->python_obj;
    }

    function __call($name, $arguments)
    {
        return $this->requirePythonObject("$name()")->$name(...$arguments);
    }

    function __get($name)
    {
        return $this->requirePythonObject("\$$name")->$name;
    }

    function __set($name, $value)
    {
        return $this->requirePythonObject("\$$name")->$name = $value;
    }

    function __isset($name)
    {
        return $this->python_obj instanceof PythonObject && isset($this->python_obj->$name);
    }

    function __invoke(...$arguments)
    {
        return ($this->requirePythonObject('__invoke()'))(...$arguments);
    }

    public function __toString()
    {
        if (is_array($this->python_obj)) return json_encode($this->python_obj);
        return (string)$this->python_obj;
    }

    public function offsetExists($offset): bool
    {
        if (is_array($this->python_obj)) return isset($this->python_obj[$offset]);
        if ($this->python_obj instanceof ArrayAccess) return $this->python_obj->offsetExists($offset);
        return false;
    }

    public function offsetGet($offset): mixed
    {
        if (is_array($this->python_obj)) return $this->python_obj[$offset];
        if ($this->python_obj instanceof ArrayAccess) return $this->python_obj->offsetGet($offset);
        throw new \LogicException(static::class . ': wrapped ' . get_debug_type($this->python_obj) . ' does not support array access');
    }

    public function offsetSet($offset, $value): void
    {
        if (is_array($this->python_obj)) {
            if ($offset === null) $this->python_obj[] = $value;
            else $this->python_obj[$offset] = $value;
            return;
        }
        if ($this->python_obj instanceof ArrayAccess) {
            $this->python_obj->offsetSet($offset, $value);
            return;
        }
        throw new \LogicException(static::class . ': wrapped ' . get_debug_type($this->python_obj) . ' does not support array access');
    }

    public function offsetUnset($offset): void
    {
        if (is_array($this->python_obj)) {
            unset($this->python_obj[$offset]);
            return;
        }
        if ($this->python_obj instanceof ArrayAccess) {
            $this->python_obj->offsetUnset($offset);
            return;
        }
        throw new \LogicException(static::class . ': wrapped ' . get_debug_type($this->python_obj) . ' does not support array access');
    }

    public function count(): int
    {
        if (is_array($this->python_obj)) return count($this->python_obj);
        if ($this->python_obj instanceof Countable) return count($this->python_obj);
        throw new \LogicException(static::class . ': wrapped ' . get_debug_type($this->python_obj) . ' is not countable');
    }

    public function getIterator(): Traversable
    {
        if (is_array($this->python_obj)) return new ArrayIterator($this->python_obj);
        if ($this->python_obj instanceof IteratorAggregate) return $this->python_obj->getIterator();
        if ($this->python_obj instanceof Traversable) return $this->python_obj;
        return new ArrayIterator((array)$this->python_obj);
    }

    public static function __callStatic($name, $arguments)
    {
        return self::accessPythonObject()->$name(...$arguments);
    }

    public static function accessPythonObject()
    {
        $python_bridge = PythonBridge::startOrGetRunning();

        [$php_class, $module, $command] = self::getCalledClass();

        $python_bridge->importModule($module);
        return $python_bridge->eval($command);
    }

    private static function constructPythonObject(...$args)
    {
        $python_bridge = PythonBridge::startOrGetRunning();

        [$php_class, $module, $command] = self::getCalledClass();

        $python_bridge->importModule($module);
        return $python_bridge->eval($command)(...$args);
    }

    function __destruct()
    {
        if ($this->python_obj instanceof PythonObject) $this->python_obj->__destruct();
    }
}
