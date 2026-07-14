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
    ){

    }

    public static function fromArray(array $package): self
    {
        return new self(
            $package['name'] ?? null,
            isset($package['version']) ? new PackageVersion($package['version']) : new PackageVersion("*"),
            index_url: $package['index-url'] ?? null,
            path: $package['path'] ?? null,
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

    /**
     * Returns the pip install specifier: the local path if set, otherwise name+version.
     * Path packages are reinstalled from their original location rather than by name/version.
     */
    public function getInstallSpec(): string
    {
        if ($this->path !== null) {
            return $this->path;
        }
        return $this->name . $this->version->convertToPip();
    }
}
