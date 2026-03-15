<?php

declare(strict_types=1);
namespace SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(enabled: false)]
class Service
{
    protected function __construct(
        public readonly string $name,
        public readonly string $version,
    ) {

    }

    public static function fromArray(array $source): self
    {
        return new self(
            name: $source["name"],
            version: $source["version"],
        );
    }
}