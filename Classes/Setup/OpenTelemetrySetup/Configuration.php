<?php

declare(strict_types=1);
namespace SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration\Service;

#[Flow\Proxy(enabled: false)]
class Configuration
{
    protected function __construct(
        public readonly string $uri,
        public readonly Service $service,
        public readonly array $options = [],
        public readonly ?ConfigurationManager $configurationManager = null,
    ) {

    }

    public static function fromArray(array $source, ?ConfigurationManager $configurationManager = null): self
    {
        $configuration = new self(
            uri: $source["uri"],
            service: Service::fromArray($source["service"]),
            options: $source,
            configurationManager: $configurationManager
        );

        return $configuration;
    }
}
