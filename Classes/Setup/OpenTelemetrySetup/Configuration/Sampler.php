<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(enabled: false)]
class Sampler
{
    protected function __construct(
        public readonly SamplerName $name,
        public readonly float $arg,
    ) {
    }

    public static function fromArray(array $source): self
    {
        $name = $source['name'] ?? null;
        $samplerName = SamplerName::AlwaysOn;
        if (!\in_array($name, [null, false, ''], true)) {
            if (!\is_string($name) || ($samplerName = SamplerName::tryFrom($name)) === null) {
                throw new \InvalidArgumentException('Invalid traces.sampler.name');
            }
        }

        $arg = $source['arg'] ?? null;
        if (\in_array($arg, [null, false, ''], true)) {
            $arg = 1.0;
        }
        if (!is_numeric($arg) || !is_finite((float) $arg) || $arg < 0 || $arg > 1) {
            throw new \InvalidArgumentException('traces.sampler.arg must be a number between 0 and 1');
        }

        return new self($samplerName, (float) $arg);
    }
}
