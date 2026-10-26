<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tracing;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Error\WithHttpStatusInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;

#[Flow\Proxy(enabled: false)]
final class ExceptionRecorder
{
    /** @var \WeakMap<\Throwable, array<string, true>>|null */
    private static ?\WeakMap $recorded = null;

    public static function httpStatus(\Throwable $exception): int
    {
        return $exception instanceof WithHttpStatusInterface ? $exception->getStatusCode() : 500;
    }

    /** Record exception details once per trace, independently of the operation's status. */
    public static function record(SpanInterface $span, \Throwable $exception, iterable $attributes = []): void
    {
        if (!$span->isRecording() || !$span->getContext()->isValid()) {
            return;
        }

        self::$recorded ??= new \WeakMap();
        $traceId = $span->getContext()->getTraceId();
        $traces = self::$recorded[$exception] ?? [];
        if (!isset($traces[$traceId])) {
            $traces[$traceId] = true;
            self::$recorded[$exception] = $traces;
            $span->recordException($exception, $attributes);
        }
    }

    /** Call when an exception causes the instrumented operation to fail. */
    public static function recordFailure(SpanInterface $span, \Throwable $exception, bool $httpServer = false): void
    {
        if (!$span->isRecording() || !$span->getContext()->isValid()) {
            return;
        }
        self::record($span, $exception);

        if ($httpServer) {
            $status = self::httpStatus($exception);
            if ($status >= 400 && $status < 500) {
                return;
            }
        }

        $span->setAttribute('error.type', $exception::class);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
    }
}
