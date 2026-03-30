<?php

namespace Python_In_PHP\Plugin\Python\Entities;

class Package
{
    function __construct(
        public string $name,
        public ?PackageVersion $version = new PackageVersion("*"),
        public bool $from_included_package = false,
    ){

    }

    public static function fromArray(array $package): self
    {
        return new self($package['name'], new PackageVersion($package['version']));
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'version' => $this->version->toString()];
    }
}