<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Tracing;

use Neos\Flow\Error\WithHttpStatusInterface;
use OpenTelemetry\API\Instrumentation\Configurator;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as LogExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use SJS\Flow\OpenTelemetry\Tracing\ExceptionRecorder;

final class ExceptionRecorderTest extends TestCase
{
    private const VARIABLE = 'OTEL_SEMCONV_EXCEPTION_SIGNAL_OPT_IN';
    private string|false $previousEnvironment;
    private mixed $previousServerValue;
    private InMemoryExporter $spans;
    private LogExporter $logs;
    private TracerProvider $tracerProvider;
    private LoggerProvider $loggerProvider;
    private ScopeInterface $loggerScope;
    private array $active = [];

    protected function setUp(): void
    {
        $this->previousEnvironment = getenv(self::VARIABLE);
        $this->previousServerValue = $_SERVER[self::VARIABLE] ?? null;
        $this->mode(null);
        $this->spans = new InMemoryExporter();
        $this->logs = new LogExporter();
        $this->tracerProvider = TracerProvider::builder()->addSpanProcessor(new SimpleSpanProcessor($this->spans))->build();
        $this->loggerProvider = LoggerProvider::builder()->addLogRecordProcessor(new SimpleLogRecordProcessor($this->logs))->build();
        $this->loggerScope = Configurator::create()->withLoggerProvider($this->loggerProvider)->storeInContext()->activate();
    }

    protected function tearDown(): void
    {
        while ($this->active !== []) {
            $this->end();
        }
        $this->tracerProvider->shutdown();
        $this->loggerProvider->shutdown();
        $this->loggerScope->detach();
        putenv($this->previousEnvironment === false ? self::VARIABLE : self::VARIABLE . '=' . $this->previousEnvironment);
        if ($this->previousServerValue === null) {
            unset($_SERVER[self::VARIABLE]);
        } else {
            $_SERVER[self::VARIABLE] = $this->previousServerValue;
        }
    }

    /** @dataProvider modes */
    public function testOptInControlsExceptionSignalWithoutChangingFailureStatus(?string $mode, int $eventCount, int $logCount): void
    {
        $this->mode($mode);
        $exception = new \RuntimeException('failure', 0, new \LogicException('original cause'));
        $root = $this->start('server', SpanKind::KIND_SERVER);
        $middle = $this->start('service');
        $origin = $this->start('origin');
        ExceptionRecorder::recordFailure($origin, $exception);
        $this->end();
        ExceptionRecorder::recordFailure($middle, $exception);
        $this->end();
        ExceptionRecorder::recordFailure($root, $exception, httpServer: true);
        $this->end();
        // The uncaught exception handler may run without a current span; do not duplicate its structured event.
        ExceptionRecorder::record(Span::getCurrent(), $exception);

        $spans = $this->spans->getSpans();
        self::assertCount(3, $spans);
        self::assertSame($eventCount, array_sum(array_map(static fn ($span) => count($span->getEvents()), $spans)));
        foreach ([$spans[0], $spans[2]] as $span) {
            self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
            self::assertSame(\RuntimeException::class, $span->getAttributes()->get('error.type'));
        }
        self::assertSame(StatusCode::STATUS_UNSET, $spans[1]->getStatus()->getCode());
        self::assertNull($spans[1]->getAttributes()->get('error.type'));
        self::assertCount($logCount, $this->logs->getStorage());
        if ($logCount) {
            $record = $this->logs->getStorage()[0];
            self::assertSame('exception', $record->getEventName());
            self::assertSame($origin->getContext()->getTraceId(), $record->getSpanContext()->getTraceId());
            self::assertSame($origin->getContext()->getSpanId(), $record->getSpanContext()->getSpanId());
            self::assertSame(Severity::ERROR->value, $record->getSeverityNumber());
            self::assertSame('failure', $record->getAttributes()->get('exception.message'));
            self::assertStringContainsString('original cause', $record->getAttributes()->get('exception.stacktrace'));
        }
    }

    public static function modes(): iterable
    {
        yield 'unset' => [null, 1, 0];
        yield 'logs' => ['logs', 0, 1];
        yield 'logs/dup' => ['logs/dup', 1, 1];
        yield 'unknown retains legacy' => ['invalid', 1, 0];
    }

    public function testExplicitSpanContextIsUsedAndAdditionalDataIsPreserved(): void
    {
        $this->mode('logs');
        $target = $this->start('target');
        $current = $this->start('current');
        ExceptionRecorder::record($target, new \RuntimeException('handled'), ['flow.additionalData.count' => 42]);
        self::assertSame($current, Span::getCurrent());
        $record = $this->logs->getStorage()[0];
        self::assertSame($target->getContext()->getSpanId(), $record->getSpanContext()->getSpanId());
        self::assertSame(42, $record->getAttributes()->get('flow.additionalData.count'));
        $this->end();
        $this->end();
        self::assertSame(StatusCode::STATUS_UNSET, $this->spans->getSpans()[1]->getStatus()->getCode());
    }

    /** @dataProvider logModes */
    public function testLogModesPreserveUnsampledExceptions(string $mode): void
    {
        $this->mode($mode);
        $context = \OpenTelemetry\API\Trace\SpanContext::create('0123456789abcdef0123456789abcdef', '0123456789abcdef', 0);
        $span = Span::wrap($context);
        ExceptionRecorder::recordFailure($span, new \RuntimeException('unsampled failure'));
        self::assertCount(1, $this->logs->getStorage());
        self::assertSame($context, $this->logs->getStorage()[0]->getSpanContext());
        self::assertFalse($span->isRecording());
    }

    public static function logModes(): iterable
    {
        yield ['logs'];
        yield ['logs/dup'];
    }

    public function testExceptionOutsideATraceIsStillLoggedOnlyOnce(): void
    {
        $this->mode('logs');
        $exception = new \RuntimeException('outside trace');
        ExceptionRecorder::record(Span::getInvalid(), $exception);
        ExceptionRecorder::record(Span::getInvalid(), $exception);
        self::assertCount(1, $this->logs->getStorage());
        self::assertSame('outside trace', $this->logs->getStorage()[0]->getAttributes()->get('exception.message'));
        self::assertFalse($this->logs->getStorage()[0]->getSpanContext()->isValid());
    }

    public function testSameExceptionCanBeReportedInDifferentTraces(): void
    {
        $this->mode('logs');
        $exception = new \RuntimeException('reused exception');
        $first = $this->start('first');
        ExceptionRecorder::recordFailure($first, $exception);
        $this->end();
        $second = $this->start('second');
        ExceptionRecorder::recordFailure($second, $exception);
        $this->end();
        self::assertCount(2, $this->logs->getStorage());
        self::assertNotSame($first->getContext()->getTraceId(), $second->getContext()->getTraceId());
        foreach ($this->spans->getSpans() as $span) {
            self::assertSame(StatusCode::STATUS_ERROR, $span->getStatus()->getCode());
        }
    }

    public function testHttp4xxExceptionsLeaveAllSpansUnsetInLogMode(): void
    {
        $this->mode('logs');
        $exception = new class ('not found') extends \RuntimeException implements WithHttpStatusInterface {
            public function getStatusCode(): int { return 404; }
        };
        $server = $this->start('server', SpanKind::KIND_SERVER);
        $child = $this->start('failed internal operation');
        ExceptionRecorder::recordFailure($child, $exception);
        $this->end();
        ExceptionRecorder::recordFailure($server, $exception, httpServer: true);
        $this->end();
        [$childData, $serverData] = $this->spans->getSpans();
        self::assertSame(StatusCode::STATUS_UNSET, $childData->getStatus()->getCode());
        self::assertNull($childData->getAttributes()->get('error.type'));
        self::assertSame(StatusCode::STATUS_UNSET, $serverData->getStatus()->getCode());
        self::assertNull($serverData->getAttributes()->get('error.type'));
        self::assertCount(1, $this->logs->getStorage());
    }

    public function testRecordingDetailsBeforeFailureDoesNotConsumeTheOrigin(): void
    {
        $exception = new \RuntimeException('logged first');
        $parent = $this->start('parent');
        ExceptionRecorder::record($parent, $exception);
        $origin = $this->start('origin');
        ExceptionRecorder::recordFailure($origin, $exception);
        ExceptionRecorder::recordFailure($origin, $exception);
        $this->end();
        ExceptionRecorder::recordFailure($parent, $exception);
        $this->end();

        [$originData, $parentData] = $this->spans->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $originData->getStatus()->getCode());
        self::assertSame(StatusCode::STATUS_UNSET, $parentData->getStatus()->getCode());
        self::assertCount(1, $parentData->getEvents());
        self::assertCount(0, $originData->getEvents());
    }

    public function testServerEnvironmentIsSupportedWhenGetenvHasNoValue(): void
    {
        $_SERVER[self::VARIABLE] = 'logs';
        $span = $this->start('server environment');
        ExceptionRecorder::recordFailure($span, new \RuntimeException('failure'));
        $this->end();
        self::assertCount(1, $this->logs->getStorage());
        self::assertCount(0, $this->spans->getSpans()[0]->getEvents());
    }

    private function start(string $name, int $kind = SpanKind::KIND_INTERNAL): SpanInterface
    {
        $builder = $this->tracerProvider->getTracer('test')->spanBuilder($name)->setSpanKind($kind);
        if ($this->active === []) {
            $builder->setParent(Context::getRoot());
        }
        $span = $builder->startSpan();
        $this->active[] = [$span, $span->activate()];
        return $span;
    }

    private function end(): void
    {
        [$span, $scope] = array_pop($this->active);
        $scope->detach();
        $span->end();
    }

    private function mode(?string $value): void
    {
        putenv($value === null ? self::VARIABLE : self::VARIABLE . '=' . $value);
        unset($_SERVER[self::VARIABLE]);
    }
}
