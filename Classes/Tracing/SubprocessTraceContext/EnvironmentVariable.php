<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tracing\SubprocessTraceContext;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(enabled: false)]
enum EnvironmentVariable: string
{
    case TraceParent = 'TRACEPARENT';
    case TraceState = 'TRACESTATE';

    public function carrierKey(): string
    {
        return strtolower($this->value);
    }
}
