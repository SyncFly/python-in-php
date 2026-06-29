<?php

namespace Python_In_PHP\Plugin\Python\Entities;

use Python_In_PHP\Plugin\Python\Exceptions\InvalidArgumentException;

class PackageVersion
{
    public string $version;

    function __construct(string $version)
    {
        $this->version = $version;
    }

    private function convertConstraints(string $constraint, callable $convertSingle): string
    {
        $constraint = trim($constraint);

        if (strpos($constraint, '||') !== false) {
            throw new InvalidArgumentException("Python packages does not support OR constraints (||): $constraint");
        }

        $tokens = preg_split('/\s+/', $constraint);
        return implode(",", array_map($convertSingle, $tokens));
    }

    // ===== Composer → Conda =====
    function convertToConda(): string
    {
        return $this->convertConstraints($this->version, $this->convertSingleConda(...));
    }

    private function convertSingleConda(string $version): string
    {
        $version = trim($version);

        if ($version == '*'){
            return '';
        }

        // ^ → emulate range
        if (strpos($version, '^') === 0) {
            if (!preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $version, $m)) {
                throw new InvalidArgumentException("Invalid ^ version: $version");
            }
            $major = $m[1];
            $minor = $m[2] ?? '0';
            $patch = $m[3] ?? '0';
            $lower = "$major.$minor.$patch";
            $upper = ((int)$major + 1) . ".0.0";
            return ">=$lower,<{$upper}";
        }

        // ~ → emulate range
        if (strpos($version, '~') === 0) {
            if (!preg_match('/^~(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $version, $m)) {
                throw new InvalidArgumentException("Invalid ~ version: $version");
            }
            $major = $m[1];
            $minor = $m[2] ?? '0';
            $patch = $m[3] ?? '0';
            if (isset($m[2])) {
                $lower = "$major.$minor.$patch";
                $upper = "$major." . ((int)$minor + 1) . ".0";
            } else {
                $lower = "$major.0.0";
                $upper = ((int)$major + 1) . ".0.0";
            }
            return ">=$lower,<{$upper}";
        }

        // * → emulate wildcard range
        if (strpos($version, '*') !== false) {
            if (!preg_match('/^(\d+)(?:\.(\d+))?\.\*$/', $version, $m)) {
                throw new InvalidArgumentException("Invalid * version: $version");
            }
            $major = $m[1];
            $minor = $m[2] ?? '0';
            $lower = "$major.$minor.0";
            $upper = "$major." . ((int)$minor + 1) . ".0";
            return ">=$lower,<{$upper}";
        }

        // Simple operators
        if (preg_match('/^(>=|<=|>|<|=)\s*(\d+(?:\.\d+){0,2})$/', $version, $m)) {
            return "{$m[1]}{$m[2]}";
        }

        // Exact version
        if (preg_match('/^\d+(?:\.\d+){0,2}$/', $version)) {
            return $version;
        }

        throw new InvalidArgumentException("Unsupported constraint for Conda: $version");
    }

    // ===== Composer → Pip =====
    function convertToPip(): string
    {
        return $this->convertConstraints($this->version, $this->convertSinglePip(...));
    }

    private function convertSinglePip(string $version): string {
        $version = trim($version);

        if ($version == '*'){
            return '';
        }

        // ^ → emulate range for pip
        if (strpos($version, '^') === 0) {
            if (!preg_match('/^\^(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $version, $m)) {
                throw new InvalidArgumentException("Invalid ^ version: $version");
            }
            $major = $m[1];
            $minor = $m[2] ?? '0';
            $patch = $m[3] ?? '0';
            return ">=$major.$minor.$patch,<" . ((int)$major + 1) . ".0.0";
        }

        // ~ → convert to ~= for pip
        if (strpos($version, '~') === 0) {
            if (!preg_match('/^~(\d+)(?:\.(\d+))?(?:\.(\d+))?$/', $version, $m)) {
                throw new InvalidArgumentException("Invalid ~ version: $version");
            }
            $major = $m[1];
            $minor = $m[2] ?? null;
            $patch = $m[3] ?? null;
            if ($minor !== null && $patch !== null) {
                return "~={$major}.{$minor}.{$patch}";
            } elseif ($minor !== null) {
                return "~={$major}.{$minor}.0";
            } else {
                return "~={$major}.0.0";
            }
        }

        // * → emulate wildcard range for pip
        if (strpos($version, '*') !== false) {
            if (!preg_match('/^(\d+)(?:\.(\d+))?\.\*$/', $version, $m)) {
                throw new InvalidArgumentException("Invalid * version: $version");
            }
            $major = $m[1];
            $minor = $m[2] ?? '0';
            $lower = "$major.$minor.0";
            $upper = "$major." . ((int)$minor + 1) . ".0";
            return ">=$lower,<{$upper}";
        }

        // Simple operators
        if (preg_match('/^(>=|<=|>|<|=)\s*(\d+(?:\.\d+){0,2})$/', $version, $m)) {
            $op = $m[1] === "=" ? "==" : $m[1];
            return "{$op}{$m[2]}";
        }

        // Exact version
        if (preg_match('/^\d+(?:\.\d+){0,2}$/', $version)) {
            return "==$version";
        }

        // PEP 440 local version identifier (e.g. 2.7.0+rocm6.3, 2.0.0+cu118)
        if (preg_match('/^\d+(?:\.\d+)*(?:\+[a-zA-Z0-9._]+)+$/', $version)) {
            return "==$version";
        }

        return $version;
    }

    public function toString()
    {
        return $this->version;
    }
}