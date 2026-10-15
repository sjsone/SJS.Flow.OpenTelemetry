<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Tracing;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Neos\Flow\Aop\Advice\AdviceChain;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Cli\Command;
use Neos\Flow\Cli\Request as CliRequest;
use Neos\Flow\Cli\Response as CliResponse;
use Neos\Flow\Core\ApplicationContext;
use Neos\Flow\Core\Booting\Step;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Metrics\NoopMeterProvider;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SJS\Flow\OpenTelemetry\Aspects\CliDispatchAspect;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;
use SJS\Flow\OpenTelemetry\Middleware\HttpTracingMiddleware;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;
use SJS\Flow\OpenTelemetry\Tracing\CompileTimeCommandTracing;
use SJS\Flow\OpenTelemetry\Tracing\TraceContext;

final class EntrySpanTest extends TestCase
{
    private OpenTelemetrySetup $setup;
    private InMemoryExporter $exporter;
    private \ReflectionProperty $defaultSetup;
    private ?OpenTelemetrySetup $previousSetup;
    private array $previousEnvironment;

    protected function setUp(): void
    {
        $this->previousEnvironment = ['TRACEPARENT' => getenv('TRACEPARENT'), 'TRACESTATE' => getenv('TRACESTATE')];
        putenv('TRACEPARENT');
        putenv('TRACESTATE');
        $this->exporter = new InMemoryExporter();
        $provider = TracerProvider::builder()->addSpanProcessor(new SimpleSpanProcessor($this->exporter))->build();
        $this->setup = (new \ReflectionClass(OpenTelemetrySetup::class))->newInstanceWithoutConstructor();
        foreach (['tracer' => $provider->getTracer('test'), 'meterProvider' => new NoopMeterProvider(), 'additionalAttributes' => []] as $name => $value) {
            $property = new \ReflectionProperty(OpenTelemetrySetup::class, $name);
            $property->setAccessible(true);
            $property->setValue($this->setup, $value);
        }
        $this->defaultSetup = new \ReflectionProperty(OpenTelemetryManager::class, 'defaultSetup');
        $this->defaultSetup->setAccessible(true);
        $this->previousSetup = $this->defaultSetup->getValue();
        $this->defaultSetup->setValue(null, $this->setup);
    }

    protected function tearDown(): void
    {
        $this->shutdown($this->setup);
        $this->defaultSetup->setValue(null, $this->previousSetup);
        foreach ($this->previousEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testHttpMiddlewareOwnsTheEntryAndItsChildren(): void
    {
        $handler = new class ($this->setup) implements RequestHandlerInterface {
            public function __construct(private OpenTelemetrySetup $setup)
            {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $child = $this->setup->startNewSpanOfKind('controller', SpanKind::KIND_INTERNAL);
                $this->setup->endSpan($child);
                return new Response(201);
            }
        };
        $response = (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/articles/123'), $handler);
        self::assertSame(201, $response->getStatusCode());
        [$child, $entry] = $this->exporter->getSpans();
        self::assertSame('HTTP GET', $entry->getName());
        self::assertSame(SpanKind::KIND_SERVER, $entry->getKind());
        self::assertSame($entry->getSpanId(), $child->getParentSpanId());
        self::assertSame(201, $entry->getAttributes()->get('http.response.status_code'));
        self::assertSame('/articles/123', $entry->getAttributes()->get('url.path'));
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertFalse(getenv('TRACEPARENT'));
        self::assertSame([], $entry->getEvents());
    }

    public function testHttpExceptionClosesTheEntryAndPreservesTheException(): void
    {
        $exception = new \RuntimeException('test failure');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException($exception);
        try {
            (new HttpTracingMiddleware())->process(new ServerRequest('POST', 'https://example.com/'), $handler);
            self::fail('The middleware must propagate the exception.');
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }
        [$entry] = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $entry->getStatus()->getCode());
        self::assertTrue($entry->hasEnded());
        self::assertFalse(getenv('TRACEPARENT'));
    }

    public function testHttpServerErrorMarksTheEntryAsFailed(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response(503));
        (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/'), $handler);
        [$entry] = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $entry->getStatus()->getCode());
        self::assertSame('503', $entry->getAttributes()->get('error.type'));
    }

    public function testHttpHeadersContinueTheRemoteTraceWithoutChangingTheEnvironment(): void
    {
        $environmentParent = '00-11111111111111111111111111111111-1111111111111111-01';
        putenv('TRACEPARENT=' . $environmentParent);
        putenv('TRACESTATE=vendor=environment');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () use ($environmentParent) {
            self::assertTrue(TraceContext::isHttp());
            self::assertSame('vendor=header', (string) Span::getCurrent()->getContext()->getTraceState());
            self::assertSame($environmentParent, getenv('TRACEPARENT'));
            self::assertSame('vendor=environment', getenv('TRACESTATE'));
            $child = $this->setup->startNewSpanOfKind('controller', SpanKind::KIND_INTERNAL);
            try {
                self::assertSame($environmentParent, getenv('TRACEPARENT'));
                self::assertSame('vendor=environment', getenv('TRACESTATE'));
                self::assertTrue(TraceContext::isHttp());
            } finally {
                $this->setup->endSpan($child);
            }
            return new Response(200);
        });
        $request = new ServerRequest('GET', 'https://example.com/', [
            'TraceParent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            'TraceState' => 'vendor=header'
        ]);
        (new HttpTracingMiddleware())->process($request, $handler);
        [$child, $entry] = $this->exporter->getSpans();
        self::assertSame('0123456789abcdef0123456789abcdef', $entry->getTraceId());
        self::assertSame('0123456789abcdef', $entry->getParentSpanId());
        self::assertSame('vendor=header', (string) $entry->getContext()->getTraceState());
        self::assertSame($entry->getSpanId(), $child->getParentSpanId());
        self::assertSame($environmentParent, getenv('TRACEPARENT'));
        self::assertSame('vendor=environment', getenv('TRACESTATE'));
        self::assertFalse(TraceContext::isHttp());
    }

    public function testHttpMissingOrInvalidHeadersStartANewTraceAndIgnoreTheEnvironment(): void
    {
        putenv('TRACEPARENT=00-11111111111111111111111111111111-1111111111111111-01');
        foreach (['', 'invalid', '00-00000000000000000000000000000000-0123456789abcdef-01'] as $header) {
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->method('handle')->willReturn(new Response(200));
            (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/', ['traceparent' => $header]), $handler);
            $spans = $this->exporter->getSpans();
            $entry = end($spans);
            self::assertSame('0000000000000000', $entry->getParentSpanId());
            self::assertNotSame('11111111111111111111111111111111', $entry->getTraceId());
            self::assertFalse(TraceContext::isHttp());
        }
    }

    public function testHttpUnsampledHeaderPreservesSamplingAndRestoresTheScope(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(static function () {
            self::assertSame('0123456789abcdef0123456789abcdef', Span::getCurrent()->getContext()->getTraceId());
            self::assertFalse(Span::getCurrent()->getContext()->isSampled());
            self::assertTrue(TraceContext::isHttp());
            self::assertFalse(getenv('TRACEPARENT'));
            self::assertFalse(getenv('TRACESTATE'));
            return new Response(200);
        });
        (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/', [
            'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-00'
        ]), $handler);
        self::assertSame([], $this->exporter->getSpans());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertFalse(TraceContext::isHttp());
    }

    public function testHttpPreservesAnAlreadyActiveLocalParent(): void
    {
        $localContext = SpanContext::create('22222222222222222222222222222222', '2222222222222222', TraceFlags::SAMPLED);
        $scope = Span::wrap($localContext)->activate();
        try {
            $handler = $this->createMock(RequestHandlerInterface::class);
            $handler->method('handle')->willReturn(new Response(200));
            (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/', [
                'traceparent' => '00-11111111111111111111111111111111-1111111111111111-01'
            ]), $handler);
            [$entry] = $this->exporter->getSpans();
            self::assertSame($localContext->getTraceId(), $entry->getTraceId());
            self::assertSame($localContext->getSpanId(), $entry->getParentSpanId());
            self::assertSame($localContext, Span::getCurrent()->getContext());
            self::assertFalse(TraceContext::isHttp());
        } finally {
            $scope->detach();
        }
    }

    public function testHttpTransportSurvivesAnExplicitParentWithoutARequestMarker(): void
    {
        putenv('TRACEPARENT=unchanged');
        putenv('TRACESTATE=vendor=unchanged');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () {
            $child = $this->setup->startNewSpanOfKind('independent operation', SpanKind::KIND_INTERNAL, [], Context::getRoot());
            try {
                self::assertTrue(TraceContext::isHttp());
                self::assertSame('unchanged', getenv('TRACEPARENT'));
                self::assertSame('vendor=unchanged', getenv('TRACESTATE'));
            } finally {
                $this->setup->endSpan($child);
            }
            self::assertSame(Context::getCurrent(), TraceContext::extract());
            return new Response(200);
        });
        (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/'), $handler);
        self::assertTrue(TraceContext::isCli());
        self::assertSame('unchanged', getenv('TRACEPARENT'));
        self::assertSame('vendor=unchanged', getenv('TRACESTATE'));
    }

    public function testCliDispatchOwnsTheEntryAndRecordsExitCode(): void
    {
        putenv('TRACEPARENT=00-0123456789abcdef0123456789abcdef-0123456789abcdef-01');
        $response = new CliResponse();
        $response->setExitCode(12);
        $joinPoint = $this->cliJoinPoint($response, function () use ($response) {
            self::assertTrue(Span::getCurrent()->getContext()->isValid());
            return $response;
        });
        self::assertSame($response, (new CliDispatchAspect())->traceCliDispatcherDispatch($joinPoint));
        [$entry] = $this->exporter->getSpans();
        self::assertSame('CLI test:run', $entry->getName());
        self::assertSame(SpanKind::KIND_INTERNAL, $entry->getKind());
        self::assertSame('0123456789abcdef0123456789abcdef', $entry->getTraceId());
        self::assertSame('0123456789abcdef', $entry->getParentSpanId());
        self::assertSame(12, $entry->getAttributes()->get('process.exit.code'));
        self::assertSame(StatusCode::STATUS_ERROR, $entry->getStatus()->getCode());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertSame('00-0123456789abcdef0123456789abcdef-0123456789abcdef-01', getenv('TRACEPARENT'));
    }

    public function testCliExceptionClosesTheEntry(): void
    {
        $exception = new \RuntimeException('command failure');
        $joinPoint = $this->cliJoinPoint(new CliResponse(), static function () use ($exception) {
            throw $exception; });
        try {
            (new CliDispatchAspect())->traceCliDispatcherDispatch($joinPoint);
            self::fail('The aspect must propagate the exception.');
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }
        [$entry] = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $entry->getStatus()->getCode());
        self::assertFalse(getenv('TRACEPARENT'));
    }

    public function testCompiletimeLifecycleIgnoresRuntimeAndClosesOnlyOnce(): void
    {
        $tracing = new CompileTimeCommandTracing('neos.flow:cache:flush');
        $step = new Step('neos.flow:configuration', static function () {});
        $tracing->afterInvokeStep($step, 'runtime');
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        $tracing->afterInvokeStep($step, 'compiletime');
        $tracing->afterInvokeStep($step, 'compiletime');
        self::assertTrue(Span::getCurrent()->getContext()->isValid());
        $tracing->finish();
        $tracing->finish();
        self::assertCount(1, $this->exporter->getSpans());
        self::assertSame('CLI neos.flow:cache:flush', $this->exporter->getSpans()[0]->getName());
        self::assertFalse(getenv('TRACEPARENT'));
    }

    public function testFallbackClosesEveryRemainingSpanWithoutARootSentinel(): void
    {
        $entry = $this->setup->startNewSpanOfKind('CLI interrupted', SpanKind::KIND_INTERNAL);
        $this->setup->startNewSpanOfKind('child', SpanKind::KIND_INTERNAL);
        $this->shutdown($this->setup);
        $this->shutdown($this->setup);
        [$child, $exportedEntry] = $this->exporter->getSpans();
        self::assertSame($entry->getContext()->getSpanId(), $child->getParentSpanId());
        self::assertTrue($exportedEntry->hasEnded());
        self::assertCount(2, $this->exporter->getSpans());
        self::assertSame('span.forcefullyClosed', $exportedEntry->getEvents()[0]->getName());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
        self::assertFalse(getenv('TRACEPARENT'));
    }

    public function testConstructingSetupDoesNotCreateOrActivateASpan(): void
    {
        $setup = new OpenTelemetrySetup(Configuration::fromArray([
            'uri' => 'http://127.0.0.1:9',
            'service' => ['name' => 'test', 'version' => '1']
        ]), new ApplicationContext('Testing'));
        try {
            self::assertFalse(Span::getCurrent()->getContext()->isValid());
            self::assertFalse(getenv('TRACEPARENT'));
            $scopes = new \ReflectionProperty(OpenTelemetrySetup::class, 'scopesWithSpans');
            $scopes->setAccessible(true);
            self::assertSame([], $scopes->getValue($setup));
        } finally {
            $this->shutdown($setup);
            Context::storage()->scope()->detach();
        }
    }

    private function cliJoinPoint(CliResponse $response, callable $callback): JoinPointInterface
    {
        $command = $this->createMock(Command::class);
        $command->method('getCommandIdentifier')->willReturn('test:run');
        $request = $this->createMock(CliRequest::class);
        $request->method('getCommand')->willReturn($command);
        $chain = $this->createMock(AdviceChain::class);
        $chain->method('proceed')->willReturnCallback($callback);
        $joinPoint = $this->createMock(JoinPointInterface::class);
        $joinPoint->method('getMethodArgument')->willReturnCallback(static fn(string $name) => $name === 'request' ? $request : $response);
        $joinPoint->method('getAdviceChain')->willReturn($chain);
        return $joinPoint;
    }

    private function shutdown(OpenTelemetrySetup $setup): void
    {
        $shutdown = new \ReflectionMethod(OpenTelemetrySetup::class, 'shutdown');
        $shutdown->setAccessible(true);
        $shutdown->invoke($setup);
    }
}
