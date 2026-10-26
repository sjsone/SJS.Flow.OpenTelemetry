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
use SJS\Flow\OpenTelemetry\Tracing\ExceptionRecorder;
use SJS\Flow\OpenTelemetry\Log\OpenTelemetryThrowableStorage;
use Neos\Flow\Error\WithHttpStatusInterface;
use org\bovigo\vfs\vfsStream;

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
        foreach (['tracer' => $provider->getTracer('test'), 'logger' => new \OpenTelemetry\API\Logs\NoopLogger(), 'meterProvider' => new NoopMeterProvider(), 'additionalAttributes' => []] as $name => $value) {
            $property = new \ReflectionProperty(OpenTelemetrySetup::class, $name);
            $property->setValue($this->setup, $value);
        }
        $this->defaultSetup = new \ReflectionProperty(OpenTelemetryManager::class, 'defaultSetup');
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

    public function testHttpExceptionKeepsTheEntryActiveThroughErrorPageRendering(): void
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
        self::assertSame([], $this->exporter->getSpans());
        $entryId = Span::getCurrent()->getContext()->getSpanId();
        self::assertTrue(Span::getCurrent()->isRecording());
        $errorPage = $this->setup->startNewSpanOfKind('error page', SpanKind::KIND_INTERNAL);
        $this->setup->endSpan($errorPage);
        $this->shutdown($this->setup);
        [$render, $entry] = $this->exporter->getSpans();
        self::assertSame($entryId, $render->getParentSpanId());
        self::assertSame($entryId, $entry->getSpanId());
        self::assertSame(500, $entry->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_ERROR, $entry->getStatus()->getCode());
        self::assertCount(1, $entry->getEvents());
        self::assertTrue($entry->hasEnded());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
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

    public function testExceptionIsRecordedOnceWhileEveryFailedOperationGetsErrorStatus(): void
    {
        $exception = new \RuntimeException('failure');
        $root = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_SERVER);
        $middle = $this->setup->startNewSpanOfKind('middle', SpanKind::KIND_INTERNAL);
        $origin = $this->setup->startNewSpanOfKind('origin', SpanKind::KIND_INTERNAL);
        ExceptionRecorder::recordFailure($origin, $exception);
        $this->setup->endSpan($origin);
        ExceptionRecorder::recordFailure($middle, $exception);
        $this->setup->endSpan($middle);
        ExceptionRecorder::recordFailure($root, $exception, httpServer: true);
        $this->setup->endSpan($root);
        [$originData, $middleData, $rootData] = $this->exporter->getSpans();
        self::assertCount(1, $originData->getEvents());
        self::assertArrayHasKey('exception.stacktrace', $originData->getEvents()[0]->getAttributes()->toArray());
        self::assertSame(StatusCode::STATUS_ERROR, $originData->getStatus()->getCode());
        self::assertSame(StatusCode::STATUS_ERROR, $middleData->getStatus()->getCode());
        self::assertSame(StatusCode::STATUS_ERROR, $rootData->getStatus()->getCode());
        self::assertSame('failure', $rootData->getStatus()->getDescription());
        self::assertCount(0, $middleData->getEvents());
        self::assertCount(0, $rootData->getEvents());
        foreach ([$originData, $middleData, $rootData] as $span) {
            self::assertSame(\RuntimeException::class, $span->getAttributes()->get('error.type'));
            self::assertSame('failure', $span->getStatus()->getDescription());
        }
    }

    public function testHttpServerSpanHasA4xxStatusCodeWithoutErrorStatus(): void
    {
        $exception = new class ('not found') extends \RuntimeException implements WithHttpStatusInterface {
            public function getStatusCode(): int { return 404; }
        };
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException($exception);
        try {
            (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/missing'), $handler);
            self::fail('Expected the original exception.');
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }
        $this->shutdown($this->setup);
        [$entry] = $this->exporter->getSpans();
        self::assertSame(404, $entry->getAttributes()->get('http.response.status_code'));
        self::assertSame(StatusCode::STATUS_UNSET, $entry->getStatus()->getCode());
        self::assertNull($entry->getAttributes()->get('error.type'));
    }

    /** @dataProvider failingChildOperations */
    public function test4xxExemptionAppliesOnlyToTheHttpServer(int $status, int $childKind): void
    {
        $exception = new class ('request failed', $status) extends \RuntimeException implements WithHttpStatusInterface {
            public function getStatusCode(): int { return $this->getCode(); }
        };
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () use ($exception, $childKind) {
            $child = $this->setup->startNewSpanOfKind('failed operation', $childKind);
            try {
                throw $exception;
            } catch (\Throwable $caught) {
                ExceptionRecorder::recordFailure($child, $caught);
                throw $caught;
            } finally {
                $this->setup->endSpan($child);
            }
        });
        try {
            (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/'), $handler);
            self::fail('Expected the original exception.');
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }
        // Flow logs the exception after it leaves the middleware; this must not fail the server span.
        $directory = vfsStream::setup('exceptions');
        OpenTelemetryThrowableStorage::createWithOptions(['storagePath' => $directory->url()])->logThrowable($exception);
        $this->shutdown($this->setup);
        [$child, $server] = $this->exporter->getSpans();
        self::assertSame($childKind, $child->getKind());
        self::assertSame(StatusCode::STATUS_ERROR, $child->getStatus()->getCode());
        self::assertSame($exception::class, $child->getAttributes()->get('error.type'));
        self::assertCount(1, $child->getEvents());
        self::assertSame(SpanKind::KIND_SERVER, $server->getKind());
        self::assertSame(StatusCode::STATUS_UNSET, $server->getStatus()->getCode());
        self::assertSame($status, $server->getAttributes()->get('http.response.status_code'));
        self::assertNull($server->getAttributes()->get('error.type'));
        self::assertCount(0, $server->getEvents());
    }

    public static function failingChildOperations(): iterable
    {
        foreach ([400, 404, 499] as $status) {
            yield "internal $status" => [$status, SpanKind::KIND_INTERNAL];
            yield "client $status" => [$status, SpanKind::KIND_CLIENT];
        }
    }

    public function testHandlingAChildFailureLeavesASuccessfulParentUnset(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () {
            $child = $this->setup->startNewSpanOfKind('failed operation', SpanKind::KIND_INTERNAL);
            ExceptionRecorder::recordFailure($child, new \RuntimeException('handled failure'));
            $this->setup->endSpan($child);
            return new Response(200);
        });
        (new HttpTracingMiddleware())->process(new ServerRequest('GET', 'https://example.com/'), $handler);
        [$child, $server] = $this->exporter->getSpans();
        self::assertSame(StatusCode::STATUS_ERROR, $child->getStatus()->getCode());
        self::assertSame(StatusCode::STATUS_UNSET, $server->getStatus()->getCode());
        self::assertNull($server->getAttributes()->get('error.type'));
        self::assertSame([], $server->getEvents());
    }

    public function testLoggingAHandledExceptionDoesNotFailTheCurrentOperation(): void
    {
        $directory = vfsStream::setup('exceptions');
        $storage = OpenTelemetryThrowableStorage::createWithOptions(['storagePath' => $directory->url()]);
        $span = $this->setup->startNewSpanOfKind('successful operation', SpanKind::KIND_INTERNAL);
        $storage->logThrowable(new \RuntimeException('handled failure'));
        $this->setup->endSpan($span);
        [$data] = $this->exporter->getSpans();
        self::assertCount(1, $data->getEvents());
        self::assertSame(StatusCode::STATUS_UNSET, $data->getStatus()->getCode());
        self::assertNull($data->getAttributes()->get('error.type'));
    }

    public function testThrowableStorageKeepsFlowDumpFilesAndAvoidsDuplicateExceptionEvents(): void
    {
        $directory = vfsStream::setup('exceptions');
        $storage = OpenTelemetryThrowableStorage::createWithOptions(['storagePath' => $directory->url()]);
        self::assertInstanceOf(OpenTelemetryThrowableStorage::class, $storage);
        $storage->setRequestInformationRenderer(static fn () => 'request details');
        $storage->setBacktraceRenderer(static fn () => 'backtrace details');
        $exception = new \RuntimeException('stored failure');
        $span = $this->setup->startNewSpanOfKind('request', SpanKind::KIND_SERVER);
        ExceptionRecorder::recordFailure($span, $exception, httpServer: true);
        $message = $storage->logThrowable($exception);
        $this->setup->endSpan($span);
        self::assertStringContainsString('stored failure - See also:', $message);
        self::assertCount(1, $directory->getChildren());
        $dump = $directory->getChildren()[0]->getContent();
        self::assertStringContainsString('request details', $dump);
        self::assertStringContainsString('backtrace details', $dump);
        self::assertCount(1, $this->exporter->getSpans()[0]->getEvents());

        // Logging without an active span must still persist the exception.
        self::assertStringContainsString('See also:', $storage->logThrowable(new \RuntimeException('outside a trace')));
        self::assertCount(2, $directory->getChildren());
    }

    public function testOutOfOrderEndWarnsAndMarksUnfinishedChildren(): void
    {
        $logger = $this->createMock(\OpenTelemetry\API\Logs\LoggerInterface::class);
        $logger->expects(self::once())->method('emit')->with(self::callback(static fn ($record) => (new \ReflectionProperty($record, 'severityNumber'))->getValue($record) === 13));
        // The test setup has a readonly logger; use a separate instance to install the spy.
        $setup = (new \ReflectionClass(OpenTelemetrySetup::class))->newInstanceWithoutConstructor();
        foreach (['tracer', 'meterProvider'] as $name) {
            (new \ReflectionProperty(OpenTelemetrySetup::class, $name))->setValue($setup, $this->setup->$name);
        }
        (new \ReflectionProperty(OpenTelemetrySetup::class, 'logger'))->setValue($setup, $logger);
        $parent = $setup->startNewSpanOfKind('parent', SpanKind::KIND_INTERNAL);
        $setup->startNewSpanOfKind('forgotten child', SpanKind::KIND_INTERNAL);
        $setup->endSpan($parent);
        [$child, $parentData] = $this->exporter->getSpans();
        self::assertSame('span.forcefullyClosed', $child->getEvents()[0]->getName());
        self::assertSame([], $parentData->getEvents());
        self::assertFalse(Span::getCurrent()->getContext()->isValid());
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

    public function testCliExceptionWithHttp4xxStatusStillFailsTheEntry(): void
    {
        $exception = new class ('command failure') extends \RuntimeException implements WithHttpStatusInterface {
            public function getStatusCode(): int { return 404; }
        };
        $joinPoint = $this->cliJoinPoint(new CliResponse(), static function () use ($exception) {
            throw $exception;
        });
        try {
            (new CliDispatchAspect())->traceCliDispatcherDispatch($joinPoint);
            self::fail('The aspect must propagate the exception.');
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }
        [$entry] = $this->exporter->getSpans();
        self::assertSame(SpanKind::KIND_INTERNAL, $entry->getKind());
        self::assertSame(StatusCode::STATUS_ERROR, $entry->getStatus()->getCode());
        self::assertSame($exception::class, $entry->getAttributes()->get('error.type'));
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
        $shutdown->invoke($setup);
    }
}
