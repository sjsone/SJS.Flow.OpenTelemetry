<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tracing;

use Neos\Flow\Annotations as Flow;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Context\ContextKeyInterface;
use OpenTelemetry\Context\ScopeInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Flow\Proxy(enabled: false)]
final class TraceContext
{
    /** @var ContextKeyInterface<class-string>|null */
    private static ?ContextKeyInterface $transportKey = null;

    public static function extractFromServerRequest(ServerRequestInterface $request): ContextInterface
    {
        return HttpTraceContext::extract($request);
    }

    public static function extract(): ContextInterface
    {
        return self::isCli() ? self::extractFromEnvironment() : Context::getCurrent();
    }

    public static function extractFromEnvironment(): ContextInterface
    {
        return SubprocessTraceContext::extract();
    }

    public static function activate(SpanInterface $span, ?ContextInterface $parentContext = null): ScopeInterface
    {
        $transport = self::transport($parentContext);
        return $transport::activate($span);
    }

    public static function isCli(): bool
    {
        return self::transport() === SubprocessTraceContext::class;
    }

    public static function isHttp(): bool
    {
        return self::transport() === HttpTraceContext::class;
    }

    public static function forHttp(ContextInterface $context): ContextInterface
    {
        return $context->with(self::transportKey(), HttpTraceContext::class);
    }

    /** @return class-string<HttpTraceContext>|class-string<SubprocessTraceContext> */
    private static function transport(?ContextInterface $parentContext = null): string
    {
        return $parentContext?->get(self::transportKey())
            ?? Context::getCurrent()->get(self::transportKey())
            ?? (PHP_SAPI === 'cli' ? SubprocessTraceContext::class : HttpTraceContext::class);
    }

    /** @return ContextKeyInterface<class-string> */
    private static function transportKey(): ContextKeyInterface
    {
        return self::$transportKey ??= Context::createKey('sjs.flow.opentelemetry.traceContextTransport');
    }
}
