<?php

namespace Python_In_PHP\Plugin\Python\Entities;

class Package
{
    function __construct(
        public ?string $name = null,
        public ?PackageVersion $version = new PackageVersion("*"),
        public bool $from_included_package = false,
        public ?string $index_url = null,
        public ?string $path = null,
        public ?string $locked_version = null,
        public ?array $extras = null,
    ){

    }

    public static function fromArray(array $package): self
    {
        $extras = $package['extras'] ?? null;
        if (is_string($extras)) {
            $extras = array_map('trim', explode(',', $extras));
        }

        // A legacy "name[extra]" entry is split into the clean name and its extras
        $name = $package['name'] ?? null;
        if (is_string($name) && preg_match('/^([^\[]+)\[([^\]]*)\]$/', trim($name), $m)) {
            $name = trim($m[1]);
            $extras = $extras ?: array_filter(array_map('trim', explode(',', $m[2])));
        }

        return new self(
            $name,
            isset($package['version']) ? new PackageVersion($package['version']) : new PackageVersion("*"),
            index_url: $package['index-url'] ?? null,
            path: $package['path'] ?? null,
            extras: array_values((array) $extras) ?: null,
        );
    }

    public function toArray(): array
    {
        // A path install whose distribution name couldn't be resolved is stored by path
        // alone — that's all reinstalling from a path needs.
        if ($this->path !== null && $this->name === null) {
            return ['path' => $this->path];
        }

        $result = ['name' => $this->name, 'version' => $this->version->toString()];
        if (!empty($this->extras)) {
            $result['extras'] = array_values($this->extras);
        }
        if ($this->index_url !== null) {
            $result['index-url'] = $this->index_url;
        }
        if ($this->path !== null) {
            $result['path'] = $this->path;
        }
        return $result;
    }

    /** Stable identity used to key and de-duplicate packages: the distribution name, or the path if the name is unknown. */
    public function getKey(): string
    {
        return $this->name ?? $this->path ?? '';
    }

    /** Human-readable label for console messages. */
    public function getLabel(): string
    {
        return $this->name ?? $this->path ?? '';
    }

    /** The pip requirement name including the extras suffix ("requests[socks]") when extras are set. */
    public function getNameWithExtras(): string
    {
        return $this->name . (empty($this->extras) ? '' : '[' . implode(',', $this->extras) . ']');
    }

    /**
     * Returns the pip install specifier: the local path if set, the exact locked pin next,
     * otherwise name+constraint. Path packages are reinstalled from their original location.
     */
    public function getInstallSpec(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }
        if ($this->locked_version !== null) {
            return $this->getNameWithExtras() . '==' . $this->locked_version;
        }
        return $this->getNameWithExtras() . $this->version->convertToPip();
    }

    /** Lock file entry with the exact pin, or null when there is nothing to pin. */
    public function toLockArray(): ?array
    {
        if ($this->name === null || $this->locked_version === null) {
            return null;
        }
        return ['name' => $this->name, 'version' => $this->locked_version];
    }

    /** Best-effort check that the locked pin still satisfies the composer.json constraint. */
    public function satisfiesConstraint(): bool
    {
        if ($this->locked_version === null || $this->version === null) {
            return true;
        }
        $constraint = trim($this->version->toString());
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        // The machine-specific local segment ("+cu126") never takes part in constraint checks
        $pin = explode('+', $this->locked_version)[0];
        if (!class_exists(\Composer\Semver\Semver::class)) {
            return true;
        }
        try {
            return \Composer\Semver\Semver::satisfies($pin, $constraint);
        } catch (\Throwable) {
            // Constraints composer's parser can't read (e.g. "~=1.2") are trusted as satisfied
            return true;
        }
    }
}
