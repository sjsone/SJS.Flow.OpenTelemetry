<?php

declare(strict_types=1);
namespace SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Configuration\ConfigurationManager;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration\Service;
use OpenTelemetry\API\Signals;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration\Signal;

#[Flow\Proxy(enabled: false)]
class Configuration
{
    protected function __construct(
        public readonly Signal $traces,
        public readonly Signal $logs,
        public readonly Signal $metrics,
        public readonly Service $service,
        public readonly array $options = [],
        public readonly ?ConfigurationManager $configurationManager = null,
    ) {
    }

    public static function fromArray(array $source, ?ConfigurationManager $configurationManager = null): self
    {
        $configuration = new self(
            traces: Signal::fromArray($source['traces'] ?? [], Signals::TRACE),
            logs: Signal::fromArray($source['logs'] ?? [], Signals::LOGS),
            metrics: Signal::fromArray($source['metrics'] ?? [], Signals::METRICS),
            service: Service::fromArray($source["service"]),
            options: $source,
            configurationManager: $configurationManager
        );

        return $configuration;
    }
}
