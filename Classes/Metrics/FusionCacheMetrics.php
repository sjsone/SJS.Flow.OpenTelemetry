<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Metrics;

use Neos\Flow\Annotations as Flow;
use OpenTelemetry\API\Metrics\CounterInterface;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;

#[Flow\Scope("singleton")]
class FusionCacheMetrics
{
    private ?CounterInterface $hitCounter = null;
    private ?CounterInterface $missCounter = null;

    public function recordHit(string $fusionPath): void
    {
        $this->hitCounter()->add(1, ['fusion.path' => $fusionPath]);
    }

    public function recordMiss(string $fusionPath): void
    {
        $this->missCounter()->add(1, ['fusion.path' => $fusionPath]);
    }

    private function hitCounter(): CounterInterface
    {
        if ($this->hitCounter === null) {
            $meter = OpenTelemetryManager::getDefaultSetup()->meter;
            $this->hitCounter = $meter->createCounter(
                'fusion.cache.hit',
                null,
                'Fusion cache hits'
            );
        }
        return $this->hitCounter;
    }

    private function missCounter(): CounterInterface
    {
        if ($this->missCounter === null) {
            $meter = OpenTelemetryManager::getDefaultSetup()->meter;
            $this->missCounter = $meter->createCounter(
                'fusion.cache.miss',
                null,
                'Fusion cache misses'
            );
        }
        return $this->missCounter;
    }
}
