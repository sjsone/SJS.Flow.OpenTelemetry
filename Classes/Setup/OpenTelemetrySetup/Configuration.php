<?php

declare(strict_types=1);
namespace SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;

use Neos\Flow\Annotations as Flow;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration\Service;

#[Flow\Proxy(enabled: false)]
class Configuration
{
    protected function __construct(
        public readonly string $uri,
        public readonly Service $service,
    ) {

    }

    public static function fromArray(array $source): self
    {
        $configuration = new self(
            uri: $source["uri"],
            service: Service::fromArray($source["service"])
        );

        return $configuration;
    }
}