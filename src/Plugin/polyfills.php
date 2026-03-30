<?php

// Polyfills for old PHP versions

if (!function_exists('array_last')) {
    function array_last(array $array) {
        return $array ? current(array_slice($array, -1)) : null;
    }
}

if (!function_exists('array_first')) {
    function array_first(array $array) {
        foreach ($array as $value) {
            return $value;
        }
        return null;
    }
}

if (!function_exists('array_find')) {
    function array_find(array $array, callable $callback)
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return null;
    }
}

if (!function_exists('array_find_key')) {
    function array_find_key(array $array, callable $callback)
    {
        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $key;
            }
        }

        return null;
    }
}