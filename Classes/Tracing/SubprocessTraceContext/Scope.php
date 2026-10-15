<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tracing\SubprocessTraceContext;

use Neos\Flow\Annotations as Flow;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\Context\ScopeInterface;

/**
 * Mirrors a package-managed span scope into the process environment for child processes
 */
#[Flow\Proxy(enabled: false)]
final class Scope implements ScopeInterface
{
    /** @var array<string, string|false> */
    private readonly array $previousEnvironment;

    public function __construct(private readonly ScopeInterface $scope)
    {
        $previousEnvironment = [];
        foreach (EnvironmentVariable::cases() as $variable) {
            $previousEnvironment[$variable->value] = getenv($variable->value);
        }
        $this->previousEnvironment = $previousEnvironment;

        $carrier = [];
        TraceContextPropagator::getInstance()->inject($carrier);
        foreach (EnvironmentVariable::cases() as $variable) {
            $name = $variable->value;
            $key = $variable->carrierKey();
            // Remove absent values so inherited trace state cannot leak into another span.
            putenv(isset($carrier[$key]) ? "$name=" . $carrier[$key] : $name);
        }
    }

    public function detach(): int
    {
        $result = $this->scope->detach();
        if ($result === 0) {
            foreach ($this->previousEnvironment as $name => $value) {
                putenv($value === false ? $name : "$name=$value");
            }
        }

        return $result;
    }
}
