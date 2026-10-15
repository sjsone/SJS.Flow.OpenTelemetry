<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Tracing;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TraceState;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOffSampler;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use SJS\Flow\OpenTelemetry\Tracing\SubprocessTraceContext;
use SJS\Flow\OpenTelemetry\Tracing\TraceContext;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;

final class SubprocessTraceContextTest extends TestCase
{
    private array $originalEnvironment;

    protected function setUp(): void
    {
        $this->originalEnvironment = ['TRACEPARENT' => getenv('TRACEPARENT'), 'TRACESTATE' => getenv('TRACESTATE')];
        putenv('TRACEPARENT');
        putenv('TRACESTATE');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testSuppliedContextCreatesAChildInTheSameTrace(): void
    {
        putenv('TRACEPARENT=00-0123456789abcdef0123456789abcdef-0123456789abcdef-01');
        putenv('TRACESTATE=vendor=test');
        $provider = TracerProvider::builder()->build();
        $span = $provider->getTracer('test')->spanBuilder('child')->setParent(SubprocessTraceContext::extract())->startSpan();
        try {
            self::assertSame('0123456789abcdef0123456789abcdef', $span->getContext()->getTraceId());
            self::assertSame('0123456789abcdef', $span->getParentContext()->getSpanId());
            self::assertNotSame('0123456789abcdef', $span->getContext()->getSpanId());
            self::assertSame('vendor=test', (string) $span->getContext()->getTraceState());
        } finally {
            $span->end();
            $provider->shutdown();
        }
    }

    public function testExplicitEnvironmentExtractionPreservesTheCurrentContext(): void
    {
        putenv('TRACEPARENT=00-11111111111111111111111111111111-1111111111111111-01');
        self::assertSame('11111111111111111111111111111111', Span::fromContext(TraceContext::extractFromEnvironment())->getContext()->getTraceId());

        $context = SpanContext::create('22222222222222222222222222222222', '2222222222222222', TraceFlags::SAMPLED);
        $scope = Span::wrap($context)->activate();
        try {
            self::assertSame($context, Span::fromContext(TraceContext::extractFromEnvironment())->getContext());
        } finally {
            $scope->detach();
        }
    }

    public function testInvalidOrMissingContextDoesNotCreateAParent(): void
    {
        foreach (['', 'invalid', '00-00000000000000000000000000000000-0123456789abcdef-01'] as $traceparent) {
            putenv('TRACEPARENT=' . $traceparent);
            self::assertFalse(Span::fromContext(SubprocessTraceContext::extract())->getContext()->isValid());
        }
    }

    public function testUnsampledParentRemainsRemoteAndUnsampled(): void
    {
        putenv('TRACEPARENT=00-0123456789abcdef0123456789abcdef-0123456789abcdef-00');
        $spanContext = Span::fromContext(SubprocessTraceContext::extract())->getContext();
        self::assertTrue($spanContext->isRemote());
        self::assertFalse($spanContext->isSampled());
        self::assertSame('0123456789abcdef', $spanContext->getSpanId());
    }

    public function testCurrentSpanTakesPrecedenceOverStartupEnvironment(): void
    {
        putenv('TRACEPARENT=00-11111111111111111111111111111111-1111111111111111-01');
        $localContext = SpanContext::create('22222222222222222222222222222222', '2222222222222222', TraceFlags::SAMPLED);
        $scope = Span::wrap($localContext)->activate();
        try {
            self::assertSame($localContext, Span::fromContext(SubprocessTraceContext::extract())->getContext());
        } finally {
            $scope->detach();
        }
    }

    public function testNestedScopesRestoreTheirPreviousEnvironment(): void
    {
        putenv('TRACEPARENT=original');
        putenv('TRACESTATE=');
        $parent = Span::wrap(SpanContext::create(
            '0123456789abcdef0123456789abcdef',
            '0123456789abcdef',
            TraceFlags::SAMPLED,
            new TraceState('vendor=parent')
        ));
        $parentScope = SubprocessTraceContext::activate($parent);
        try {
            $parentTraceparent = getenv('TRACEPARENT');
            self::assertSame('vendor=parent', getenv('TRACESTATE'));
            $child = Span::wrap(SpanContext::create('0123456789abcdef0123456789abcdef', 'fedcba9876543210', TraceFlags::SAMPLED));
            $childScope = SubprocessTraceContext::activate($child);
            try {
                self::assertSame('00-0123456789abcdef0123456789abcdef-fedcba9876543210-01', getenv('TRACEPARENT'));
                self::assertFalse(getenv('TRACESTATE'));
            } finally {
                $childScope->detach();
            }
            self::assertSame($parentTraceparent, getenv('TRACEPARENT'));
            self::assertSame('vendor=parent', getenv('TRACESTATE'));
        } finally {
            $parentScope->detach();
        }
        self::assertSame('original', getenv('TRACEPARENT'));
        self::assertSame('', getenv('TRACESTATE'));
    }

    public function testExecInheritsTheActiveSpanContext(): void
    {
        $parent = Span::wrap(SpanContext::create(
            '0123456789abcdef0123456789abcdef',
            '0123456789abcdef',
            TraceFlags::SAMPLED,
            new TraceState('vendor=test')
        ));
        $scope = SubprocessTraceContext::activate($parent);
        try {
            $childCode = 'require ' . var_export(FLOW_PATH_ROOT . 'Packages/Libraries/autoload.php', true) . ';'
                . '$context = \SJS\Flow\OpenTelemetry\Tracing\SubprocessTraceContext::extract();'
                . '$provider = \OpenTelemetry\SDK\Trace\TracerProvider::builder()->build();'
                . '$span = $provider->getTracer("test")->spanBuilder("child")->setParent($context)->startSpan();'
                . 'echo json_encode([$span->getContext()->getTraceId(), $span->getParentContext()->getSpanId(), (string) $span->getContext()->getTraceState()]);'
                . '$span->end(); $provider->shutdown();';
            exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($childCode), $output, $status);
            self::assertSame(0, $status);
            self::assertSame(['0123456789abcdef0123456789abcdef', '0123456789abcdef', 'vendor=test'], json_decode(implode('', $output), true));
        } finally {
            $scope->detach();
        }
        self::assertFalse(getenv('TRACEPARENT'));
        self::assertFalse(getenv('TRACESTATE'));
    }

    public function testRejectedOutOfOrderEndPreservesTheScopeStack(): void
    {
        $setup = $this->createSetup();
        $parent = $setup->startNewSpanOfKind('parent', SpanKind::KIND_INTERNAL);
        $parentTraceparent = getenv('TRACEPARENT');
        $child = $setup->startNewSpanOfKind('child', SpanKind::KIND_INTERNAL);
        $childTraceparent = getenv('TRACEPARENT');
        try {
            $setup->endSpan($parent);
            self::fail('Ending the parent before its child must be rejected.');
        } catch (\Exception $exception) {
            self::assertStringContainsString('not next in stack', $exception->getMessage());
            self::assertSame($childTraceparent, getenv('TRACEPARENT'));
        } finally {
            $setup->endSpan($child);
            self::assertSame($parentTraceparent, getenv('TRACEPARENT'));
            $setup->endSpan($parent);
        }
        self::assertFalse(getenv('TRACEPARENT'));
        self::assertFalse(getenv('TRACESTATE'));
    }

    public function testShutdownRestoresEnvironmentForUnsampledSpans(): void
    {
        $setup = $this->createSetup(false);
        $setup->startNewSpanOfKind('CLI test', SpanKind::KIND_INTERNAL);
        $child = $setup->startNewSpanOfKind('child', SpanKind::KIND_INTERNAL);
        self::assertFalse($child->isRecording());
        self::assertStringEndsWith('-00', getenv('TRACEPARENT'));
        $shutdown = new \ReflectionMethod(OpenTelemetrySetup::class, 'shutdown');
        $shutdown->setAccessible(true);
        $shutdown->invoke($setup);
        self::assertFalse(getenv('TRACEPARENT'));
        self::assertFalse(getenv('TRACESTATE'));
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
    }

    public function testIterableAttributesOverrideSetupAttributes(): void
    {
        $setup = $this->createSetup(additionalAttributes: ['shared' => 'default', 'setup' => true]);
        $attributes = (static function (): \Generator {
            yield 'shared' => 'override';
            yield 'operation' => 'test';
        })();
        $span = $setup->startNewSpanOfKind('iterable attributes', SpanKind::KIND_INTERNAL, $attributes);
        try {
            self::assertSame('override', $span->getAttribute('shared'));
            self::assertTrue($span->getAttribute('setup'));
            self::assertSame('test', $span->getAttribute('operation'));
        } finally {
            $setup->endSpan($span);
        }
    }

    private function createSetup(bool $sampled = true, array $additionalAttributes = []): OpenTelemetrySetup
    {
        // Exercise scope management without registering shutdown callbacks or network exporters.
        $setup = (new \ReflectionClass(OpenTelemetrySetup::class))->newInstanceWithoutConstructor();
        $provider = TracerProvider::builder()->setSampler($sampled ? new AlwaysOnSampler() : new AlwaysOffSampler())->build();
        foreach (['tracer' => $provider->getTracer('test'), 'meterProvider' => MeterProvider::builder()->build(), 'additionalAttributes' => $additionalAttributes] as $name => $value) {
            $property = new \ReflectionProperty(OpenTelemetrySetup::class, $name);
            $property->setAccessible(true);
            $property->setValue($setup, $value);
        }

        return $setup;
    }
}
