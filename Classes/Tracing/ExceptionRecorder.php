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
        $mode = getenv('OTEL_SEMCONV_EXCEPTION_SIGNAL_OPT_IN');
        if ($mode === false) {
            $mode = $_SERVER['OTEL_SEMCONV_EXCEPTION_SIGNAL_OPT_IN'] ?? '';
        }

        $emitLogs = \in_array($mode, ['logs', 'logs/dup'], true);
        if (!$emitLogs && (!$span->isRecording() || !$span->getContext()->isValid())) {
            return;
        }

        self::$recorded ??= new \WeakMap();
        $traceId = $span->getContext()->getTraceId();
        $traces = self::$recorded[$exception] ?? [];
        if ($mode !== 'logs') {
            self::recordExceptionIntoSpan($span, $exception, $attributes);
        }
        if ($emitLogs) {
            self::EXCEPTION_SIGNAL_OPT_IN_recordExceptionIntoLog($span, $exception, $attributes);
        }
    }

    protected static function recordExceptionIntoSpan(SpanInterface $span, \Throwable $exception, iterable $attributes)
    {
        $span->recordException($exception, $attributes);
    }

    protected static function EXCEPTION_SIGNAL_OPT_IN_recordExceptionIntoLog(SpanInterface $span, \Throwable $exception, iterable $attributes)
    {
        $context = $span->storeInContext(Context::getCurrent());
        $logger = Globals::loggerProvider()->getLogger('SJS.Flow.OpenTelemetry');
        if ($logger->isEnabled($context, Severity::ERROR->value, 'exception')) {
            $logger->logRecordBuilder()
                ->setContext($context)
                ->setEventName('exception')
                ->setBody($exception->getMessage())
                ->setAttributes($attributes)
                ->setException($exception)
                ->setSeverityNumber(Severity::ERROR)
                ->setSeverityText('ERROR')
                ->emit();
        }
    }

    /** Mark the origin and root spans as failed, leaving propagating operations unset. */
    public static function recordFailure(SpanInterface $span, \Throwable $exception, bool $httpServer = false, bool $root = false): void
    {
        self::record($span, $exception);
        if (!$span->isRecording() || !$span->getContext()->isValid()) {
            return;
        }

        self::$failed ??= new \WeakMap();
        $context = $span->getContext();
        $origins = self::$failed[$exception] ?? [];
        $origins[$context->getTraceId()] ??= $context->getSpanId();
        self::$failed[$exception] = $origins;

        if ($origins[$context->getTraceId()] !== $context->getSpanId() && !$root && !$httpServer) {
            return;
        }

        $status = self::httpStatus($exception);
        if ($status >= 400 && $status < 500) {
            return;
        }

        $span->setAttribute('error.type', $exception::class);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
    }
}
