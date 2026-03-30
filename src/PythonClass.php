<?php

namespace Python_In_PHP;

use py\sys;
use ReflectionClass;
use ReflectionProperty;

class PythonClass
{
    private PythonObject $python_obj;

    function __construct(...$args)
    {
        $this->python_obj = self::constructPythonObject(...$args);
    }

    private static function getCalledClass(): array
    {
        $name = get_called_class();
        $path = explode('\\', $name);
        array_shift($path);

        $module = $command = implode('.', $path);

        return [$name, $module, $command];
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

    function __call($name, $arguments)
    {
        return $this->python_obj->$name(...$arguments);
    }

    function __get($name)
    {
        return $this->python_obj->$name;
    }

    function __set($name, $value)
    {
        return $this->python_obj->$name = $value;
    }

    function __isset($name)
    {
        return isset($this->python_obj->$name);
    }

    function __invoke(...$arguments)
    {
        return ($this->python_obj)(...$arguments);
    }

    public function __toString()
    {
        return (string)$this->python_obj;
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
        $this->python_obj->__destruct();
    }
}