<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;

use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(enabled: false)]
enum SamplerName: string
{
    case AlwaysOn = 'always_on';
    case AlwaysOff = 'always_off';
    case ParentBasedAlwaysOn = 'parentbased_always_on';
    case ParentBasedAlwaysOff = 'parentbased_always_off';
    case TraceIdRatio = 'traceidratio';
    case ParentBasedTraceIdRatio = 'parentbased_traceidratio';
}
