<?php

namespace Python_In_PHP\Plugin\Python\Entities;

class Package
{
    function __construct(
        public string $name,
        public ?PackageVersion $version = new PackageVersion("*"),
        public bool $from_included_package = false,
        public ?string $index_url = null,
        public ?string $path = null,
    ){

    }

    public static function fromArray(array $package): self
    {
        return new self(
            $package['name'],
            new PackageVersion($package['version']),
            index_url: $package['index-url'] ?? null,
            path: $package['path'] ?? null,
        );
    }

    public function toArray(): array
    {
        $result = ['name' => $this->name, 'version' => $this->version->toString()];
        if ($this->index_url !== null) {
            $result['index-url'] = $this->index_url;
        }
        if ($this->path !== null) {
            $result['path'] = $this->path;
        }
        return $result;
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
