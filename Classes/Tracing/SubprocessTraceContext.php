<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tracing;

use Neos\Flow\Annotations as Flow;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\Propagation\EnvironmentGetterSetter;
use OpenTelemetry\Context\ScopeInterface;
use SJS\Flow\OpenTelemetry\Tracing\SubprocessTraceContext\Scope;

#[Flow\Proxy(enabled: false)]
final class SubprocessTraceContext
{
    public static function activate(SpanInterface $span): ScopeInterface
    {
        return new Scope($span->activate());
    }

    public static function extract(): ContextInterface
    {
        $context = Context::getCurrent();
        if (Span::fromContext($context)->getContext()->isValid()) {
            return $context;
        }

        return TraceContextPropagator::getInstance()->extract(
            null,
            EnvironmentGetterSetter::getInstance(),
            $context
        );
    }
}
