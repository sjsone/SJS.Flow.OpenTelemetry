<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(enabled: false)]
class Signal
{
    protected function __construct(public readonly Transport $transport)
    {
    }

    public static function fromArray(array $source, string $signal): self
    {
        return new self(Transport::fromArray($source['transport'] ?? [], $signal));
    }
}
