<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tracing;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Booting\Step;
use Neos\Flow\Core\Bootstrap;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;

#[Flow\Proxy(enabled: false)]
final class CompileTimeCommandTracing
{
    private ?SpanInterface $span = null;
    private ?OpenTelemetrySetup $setup = null;

    public function __construct(private readonly string $command)
    {
    }

    public function afterInvokeStep(Step $step, string $runLevel): void
    {
        if (strcasecmp($runLevel, Bootstrap::RUNLEVEL_COMPILETIME) !== 0 || $step->getIdentifier() !== 'neos.flow:configuration' || $this->span !== null) {
            return;
        }

        $this->setup = OpenTelemetryManager::getDefaultSetup();
        $this->span = $this->setup->startNewSpanOfKind("CLI {$this->command}", SpanKind::KIND_INTERNAL, [
            'process.executable.name' => basename(PHP_BINARY)
        ], TraceContext::extractFromEnvironment());
    }

    public function finish(): void
    {
        if ($this->span === null) {
            return;
        }

        $this->setup->endSpan($this->span);
        $this->span = null;
        $this->setup->flushMetrics();
    }
}
