<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tracing;

use Neos\Flow\Annotations as Flow;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ScopeInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Flow\Proxy(enabled: false)]
final class HttpTraceContext
{
    public static function extract(ServerRequestInterface $request): ContextInterface
    {
        $context = Context::getCurrent();
        if (!Span::fromContext($context)->getContext()->isValid()) {
            $context = TraceContextPropagator::getInstance()->extract($request->getHeaders(), null, $context);
        }

        return TraceContext::forHttp($context);
    }

    public static function activate(SpanInterface $span): ScopeInterface
    {
        return TraceContext::forHttp($span->storeInContext(Context::getCurrent()))->activate();
    }
}
