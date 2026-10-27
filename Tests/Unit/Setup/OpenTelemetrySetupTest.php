<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Setup;

use GuzzleHttp\Psr7\Response;
use Neos\Flow\Core\ApplicationContext;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\Context\Context;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery;
use OpenTelemetry\SDK\Common\Http\Psr\Client\Discovery\DiscoveryInterface;
use OpenTelemetry\Proto\Collector\Logs\V1\ExportLogsServiceRequest;
use OpenTelemetry\Proto\Collector\Metrics\V1\ExportMetricsServiceRequest;
use OpenTelemetry\Proto\Collector\Trace\V1\ExportTraceServiceRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;
use SJS\Flow\OpenTelemetry\Metrics\FusionCacheMetrics;
use SJS\Flow\OpenTelemetry\Tracing\ExceptionRecorder;

final class OpenTelemetrySetupTest extends TestCase
{
    private array $requests = [];
    private array $environment = [];
    private ?OpenTelemetrySetup $setup = null;

    protected function setUp(): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(function (RequestInterface $request) {
            $this->requests[$request->getUri()->getPath()][] = $request;
            return new Response(200, [], $request->getHeaderLine('Content-Type') === 'application/json' ? '{}' : '');
        });
        Discovery::setDiscoverers([
            new class ($client) implements DiscoveryInterface {
            public function __construct(private ClientInterface $client)
            {}
            public function available(): bool
            {
                return true; }
            public function create(mixed $options): ClientInterface
            {
                return $this->client; }
            }
        ]);
        $this->env('OTEL_TRACES_SAMPLER', 'parentbased_always_on');
        $this->env('OTEL_TRACES_SAMPLER_ARG', '1');
        $this->env('OTEL_EXPORTER_OTLP_HEADERS', 'Authorization=Basic%20common');
        $this->env('OTEL_EXPORTER_OTLP_TRACES_HEADERS', 'Authorization=Bearer%20traces');
        $this->env('OTEL_EXPORTER_OTLP_LOGS_HEADERS', '');
        $this->env('OTEL_EXPORTER_OTLP_METRICS_HEADERS', 'Authorization=Bearer%20metrics');
    }

    protected function tearDown(): void
    {
        if ($this->setup !== null) {
            $this->shutdown();
            Context::storage()->scope()->detach();
        }
        Discovery::reset();
        foreach ($this->environment as $name => [$value, $server]) {
            putenv($value === false ? $name : $name . '=' . $value);
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
    }

    public function testSignalsAreBatchedAsAuthenticatedProtobufAndShutdownEndsSpansBeforeExport(): void
    {
        $this->createSetup();
        $root = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_SERVER, [], Context::getRoot());
        $child = $this->setup->startNewSpanOfKind('child', SpanKind::KIND_INTERNAL);
        $this->setup->endSpan($child);
        $this->setup->logger->emit(new LogRecord('one'));
        $this->setup->logger->emit(new LogRecord('two'));
        $this->setup->meter->createCounter('test.counter')->add(1);
        $this->setup->deferEndSpan($root);
        self::assertSame([], $this->requests);

        $this->shutdown();
        $this->shutdown();
        self::assertCount(3, $this->requests);
        foreach (['traces' => 'Bearer traces', 'logs' => 'Basic common', 'metrics' => 'Bearer metrics'] as $signal => $header) {
            self::assertCount(1, $this->requests['/v1/' . $signal]);
            $request = $this->requests['/v1/' . $signal][0];
            self::assertSame($header, $request->getHeaderLine('Authorization'));
            self::assertSame('application/x-protobuf', $request->getHeaderLine('Content-Type'));
        }

        $traces = new ExportTraceServiceRequest();
        $traces->mergeFromString((string) $this->requests['/v1/traces'][0]->getBody());
        $resourceSpans = $traces->getResourceSpans()[0];
        $resource = $this->attributes($resourceSpans->getResource()->getAttributes());
        self::assertSame('production-docker', $resource['deployment.environment.name']);
        self::assertSame('runtime', $resource['neos.flow.runlevel']);
        self::assertArrayNotHasKey('service.version', $resource);
        self::assertArrayNotHasKey('server.address', $resource);
        $spans = $resourceSpans->getScopeSpans()[0]->getSpans();
        self::assertCount(2, $spans);
        self::assertSame('child', $spans[0]->getName());
        self::assertSame('root', $spans[1]->getName());
        self::assertCount(0, $spans[1]->getEvents());
        self::assertArrayNotHasKey('neos.flow.runlevel', $this->attributes($spans[0]->getAttributes()));

        $logs = new ExportLogsServiceRequest();
        $logs->mergeFromString((string) $this->requests['/v1/logs'][0]->getBody());
        $records = $logs->getResourceLogs()[0]->getScopeLogs()[0]->getLogRecords();
        self::assertCount(2, $records);
        self::assertSame($spans[1]->getTraceId(), $records[0]->getTraceId());

        $metrics = new ExportMetricsServiceRequest();
        $metrics->mergeFromString((string) $this->requests['/v1/metrics'][0]->getBody());
        self::assertCount(1, $metrics->getResourceMetrics()[0]->getScopeMetrics()[0]->getMetrics());
    }

    public function testSignalsHaveIndependentEndpointsHeadersAndContentTypes(): void
    {
        $this->createSetup([
            'environment' => 'staging',
            'traces' => ['transport' => [
                'uri' => 'https://traces.test/custom-traces',
                'contentType' => 'application/json',
                'headers' => ['authorization' => 'trace credential'],
            ]],
            'logs' => ['transport' => [
                'uri' => 'http://logs.test/custom-logs',
                'contentType' => 'application/x-protobuf',
                'headers' => ['Authorization' => 'log credential'],
            ]],
            'metrics' => ['transport' => [
                'uri' => 'https://metrics.test/custom-metrics',
                'contentType' => 'application/json',
                'headers' => ['authorization' => 'metric credential'],
            ]],
        ]);
        $span = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        $this->setup->logger->emit(new LogRecord('configured transport'));
        $this->setup->meter->createCounter('test.counter')->add(1);
        $this->setup->endSpan($span);
        $this->shutdown();
        self::assertCount(3, $this->requests);
        foreach (['traces' => ['trace', 'application/json'], 'logs' => ['log', 'application/x-protobuf'], 'metrics' => ['metric', 'application/json']] as $signal => [$credential, $contentType]) {
            self::assertCount(1, $this->requests['/custom-' . $signal]);
            $request = $this->requests['/custom-' . $signal][0];
            self::assertSame($signal . '.test', $request->getUri()->getHost());
            self::assertSame($credential . ' credential', $request->getHeaderLine('Authorization'));
            self::assertSame($contentType, $request->getHeaderLine('Content-Type'));
        }
        $traces = new ExportTraceServiceRequest();
        $traces->mergeFromJsonString((string) $this->requests['/custom-traces'][0]->getBody());
        self::assertSame('staging', $this->attributes($traces->getResourceSpans()[0]->getResource()->getAttributes())['deployment.environment.name']);
        $logs = new ExportLogsServiceRequest();
        $logs->mergeFromString((string) $this->requests['/custom-logs'][0]->getBody());
        self::assertCount(1, $logs->getResourceLogs()[0]->getScopeLogs()[0]->getLogRecords());
        $metrics = new ExportMetricsServiceRequest();
        $metrics->mergeFromJsonString((string) $this->requests['/custom-metrics'][0]->getBody());
        self::assertCount(1, $metrics->getResourceMetrics()[0]->getScopeMetrics()[0]->getMetrics());
    }

    /** @dataProvider eventContentTypes */
    public function testLogBasedExceptionEventsRoundTripWithoutSpanEvents(string $contentType): void
    {
        $this->env('OTEL_SEMCONV_EXCEPTION_SIGNAL_OPT_IN', 'logs');
        $this->createSetup([
            'traces' => ['transport' => ['contentType' => $contentType]],
            'logs' => ['transport' => ['contentType' => $contentType]],
        ]);
        $span = $this->setup->startNewSpanOfKind('failed operation', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        $exception = new \RuntimeException('outer failure', 0, new \LogicException('original cause'));

        ExceptionRecorder::recordFailure($span, $exception);
        $this->setup->endSpan($span);
        $this->shutdown();

        $traces = new ExportTraceServiceRequest();
        $tracePayload = (string) $this->requests['/v1/traces'][0]->getBody();
        $logs = new ExportLogsServiceRequest();
        $logPayload = (string) $this->requests['/v1/logs'][0]->getBody();
        if ($contentType === 'application/json') {
            $traces->mergeFromJsonString($tracePayload);
            $logs->mergeFromJsonString($logPayload);
        } else {
            $traces->mergeFromString($tracePayload);
            $logs->mergeFromString($logPayload);
        }
        $exportedSpan = $traces->getResourceSpans()[0]->getScopeSpans()[0]->getSpans()[0];
        $records = $logs->getResourceLogs()[0]->getScopeLogs()[0]->getLogRecords();
        self::assertCount(1, $records);
        $record = $records[0];
        self::assertSame('exception', $record->getEventName());
        self::assertSame($exportedSpan->getTraceId(), $record->getTraceId());
        self::assertSame($exportedSpan->getSpanId(), $record->getSpanId());
        self::assertSame(Severity::ERROR->value, $record->getSeverityNumber());
        self::assertSame('ERROR', $record->getSeverityText());
        self::assertSame('outer failure', $record->getBody()->getStringValue());
        $attributes = $this->attributes($record->getAttributes());
        self::assertSame(\RuntimeException::class, $attributes['exception.type']);
        self::assertSame('outer failure', $attributes['exception.message']);
        self::assertStringContainsString('original cause', $attributes['exception.stacktrace']);
        self::assertStringContainsString('Caused by:', $attributes['exception.stacktrace']);
        self::assertCount(0, $exportedSpan->getEvents());
        self::assertSame(2, $exportedSpan->getStatus()->getCode());
        self::assertSame(\RuntimeException::class, $this->attributes($exportedSpan->getAttributes())['error.type']);
    }

    public static function eventContentTypes(): iterable
    {
        yield 'protobuf' => ['application/x-protobuf'];
        yield 'JSON' => ['application/json'];
    }

    public function testNamedLogEventsCanExplicitlyReferenceAnotherSpan(): void
    {
        $this->createSetup();
        $parent = $this->setup->startNewSpanOfKind('parent', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        $child = $this->setup->startNewSpanOfKind('child', SpanKind::KIND_INTERNAL);
        $this->setup->endSpan($child);

        $this->setup->logger->logRecordBuilder()
            ->setEventName('span.forcefullyClosed')
            ->setBody('Compatibility probe for a span lifecycle event')
            ->setSeverityNumber(Severity::WARN)
            ->setContext($child->storeInContext(Context::getCurrent()))
            ->emit();
        $this->setup->endSpan($parent);
        $this->shutdown();

        $logs = new ExportLogsServiceRequest();
        $logs->mergeFromString((string) $this->requests['/v1/logs'][0]->getBody());
        $record = $logs->getResourceLogs()[0]->getScopeLogs()[0]->getLogRecords()[0];
        self::assertSame('span.forcefullyClosed', $record->getEventName());
        self::assertSame($child->getContext()->getTraceId(), bin2hex($record->getTraceId()));
        self::assertSame($child->getContext()->getSpanId(), bin2hex($record->getSpanId()));
        self::assertNotSame($parent->getContext()->getSpanId(), bin2hex($record->getSpanId()));
        self::assertSame(Severity::WARN->value, $record->getSeverityNumber());
    }

    public function testLogBasedExceptionsCanRetainAnUnsampledTraceContext(): void
    {
        $this->env('OTEL_SEMCONV_EXCEPTION_SIGNAL_OPT_IN', 'logs');
        $this->env('OTEL_TRACES_SAMPLER', 'parentbased_always_off');
        $this->createSetup();
        $span = $this->setup->startNewSpanOfKind('unsampled operation', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        self::assertFalse($span->isRecording());
        ExceptionRecorder::recordFailure($span, new \RuntimeException('unsampled failure'));
        $this->setup->endSpan($span);
        $this->shutdown();

        self::assertArrayNotHasKey('/v1/traces', $this->requests);
        $logs = new ExportLogsServiceRequest();
        $logs->mergeFromString((string) $this->requests['/v1/logs'][0]->getBody());
        $record = $logs->getResourceLogs()[0]->getScopeLogs()[0]->getLogRecords()[0];
        self::assertSame('exception', $record->getEventName());
        self::assertSame($span->getContext()->getTraceId(), bin2hex($record->getTraceId()));
        self::assertSame($span->getContext()->getSpanId(), bin2hex($record->getSpanId()));
        self::assertSame(0, $record->getFlags() & 1);
        self::assertSame('unsampled failure', $this->attributes($record->getAttributes())['exception.message']);
    }

    public function testSamplingEnvironmentAppliesToTheEntireLocalTrace(): void
    {
        $this->env('OTEL_TRACES_SAMPLER', 'parentbased_traceidratio');
        $this->env('OTEL_TRACES_SAMPLER_ARG', '0');
        $this->createSetup();
        $root = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        $child = $this->setup->startNewSpanOfKind('child', SpanKind::KIND_INTERNAL);
        self::assertFalse($root->isRecording());
        self::assertFalse($child->isRecording());
        self::assertSame($root->getContext()->getTraceId(), $child->getContext()->getTraceId());
        $this->setup->endSpan($child);
        $this->setup->endSpan($root);
        $this->shutdown();
        self::assertArrayNotHasKey('/v1/traces', $this->requests);
    }

    /** @dataProvider configuredSamplers */
    public function testConfiguredSamplerOverridesTheEnvironment(string $name, ?float $arg, bool $recording): void
    {
        $this->env('OTEL_TRACES_SAMPLER', $recording ? 'always_off' : 'always_on');
        $this->env('OTEL_TRACES_SAMPLER_ARG', $recording ? '0' : '1');
        $this->createSetup(['traces' => ['sampler' => ['name' => $name, 'arg' => $arg]]]);
        $root = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        $child = $this->setup->startNewSpanOfKind('child', SpanKind::KIND_INTERNAL);
        self::assertSame($recording, $root->isRecording());
        self::assertSame($recording, $child->isRecording());
        $this->setup->endSpan($child);
        $this->setup->endSpan($root);
        $this->shutdown();
        self::assertSame($recording, isset($this->requests['/v1/traces']));
    }

    public static function configuredSamplers(): iterable
    {
        yield ['always_on', null, true];
        yield ['always_off', null, false];
        yield ['parentbased_always_on', null, true];
        yield ['parentbased_always_off', null, false];
        foreach (['traceidratio', 'parentbased_traceidratio'] as $name) {
            yield [$name, 0.0, false];
            yield [$name, 1.0, true];
        }
    }

    /** @dataProvider alwaysOnSamplerSettings */
    public function testAlwaysOnSettingRecordsARemoteUnsampledParent(array $sampler): void
    {
        $this->env('OTEL_TRACES_SAMPLER', 'always_off');
        $this->createSetup(['traces' => ['sampler' => $sampler]]);
        $context = \OpenTelemetry\API\Trace\SpanContext::createFromRemoteParent(
            '0123456789abcdef0123456789abcdef', '0123456789abcdef', 0
        );
        $root = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_SERVER, [],
            \OpenTelemetry\API\Trace\Span::wrap($context)->storeInContext(Context::getRoot()));
        self::assertTrue($root->isRecording());
        self::assertSame($context->getTraceId(), $root->getContext()->getTraceId());
        $this->setup->endSpan($root);
        $this->shutdown();
        self::assertCount(1, $this->requests['/v1/traces']);
    }

    public static function alwaysOnSamplerSettings(): iterable
    {
        yield 'configured' => [['name' => 'always_on']];
        yield 'unset Flow environment variables' => [['name' => false, 'arg' => false]];
    }

    public function testConfiguredRatioSamplerCanUseTheEnvironmentArgument(): void
    {
        $this->env('OTEL_TRACES_SAMPLER', 'always_on');
        $this->env('OTEL_TRACES_SAMPLER_ARG', '0');
        $this->createSetup(['traces' => ['sampler' => ['name' => 'traceidratio']]]);
        $root = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        self::assertFalse($root->isRecording());
        $this->setup->endSpan($root);
    }

    public function testTransportTimeoutAndRetryLimitsReachTheHttpClientForEverySignal(): void
    {
        $options = [];
        $attempts = [];
        $client = $this->createMock(ClientInterface::class);
        $client->method('sendRequest')->willReturnCallback(static function (RequestInterface $request) use (&$attempts) {
            $path = $request->getUri()->getPath();
            $attempts[$path] = ($attempts[$path] ?? 0) + 1;
            return new Response(503);
        });
        Discovery::setDiscoverers([new class ($client, $options) implements DiscoveryInterface {
            public function __construct(private ClientInterface $client, public array &$options) {}
            public function available(): bool { return true; }
            public function create(mixed $options): ClientInterface
            {
                $this->options[] = $options;
                return $this->client;
            }
        }]);

        $configuration = Configuration::fromArray([
            'traces' => ['transport' => ['timeout' => 2.0, 'maxRetries' => 1]],
            'logs' => ['transport' => ['timeout' => 0.5, 'maxRetries' => 0]],
            'service' => ['name' => 'test'],
        ]);
        $setup = (new \ReflectionClass(OpenTelemetrySetup::class))->newInstanceWithoutConstructor();
        foreach (['traces' => [2.0, 1], 'logs' => [0.5, 0], 'metrics' => [10.0, 3]] as $signal => [$timeout, $maxRetries]) {
            $method = new \ReflectionMethod(OpenTelemetrySetup::class, match ($signal) {
                'traces' => 'createSpanTransport', 'logs' => 'createLoggerTransport', 'metrics' => 'createMetricTransport',
            });
            $transport = $method->invoke($setup, $configuration);
            try {
                $transport->send('payload')->await();
                self::fail('An unavailable collector must exhaust the retry limit.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Export retry limit exceeded', $exception->getMessage());
            } finally {
                $transport->shutdown();
            }
            self::assertSame($timeout, $options[array_key_last($options)]['timeout']);
            self::assertSame($maxRetries + 1, $attempts['/v1/' . $signal]);
        }
    }

    public function testUnsetFlowEnvironmentVariablesUseDefaults(): void
    {
        $this->createSetup(['environment' => false, 'service' => ['name' => 'test', 'version' => false]]);
        $span = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        $this->setup->endSpan($span);
        $this->shutdown();
        $payload = new ExportTraceServiceRequest();
        $payload->mergeFromString((string) $this->requests['/v1/traces'][0]->getBody());
        $resource = $this->attributes($payload->getResourceSpans()[0]->getResource()->getAttributes());
        self::assertSame('production-docker', $resource['deployment.environment.name']);
        self::assertArrayNotHasKey('service.version', $resource);
    }

    public function testFusionPathsShareAggregateMetricSeries(): void
    {
        $this->createSetup();
        $property = new \ReflectionProperty(OpenTelemetryManager::class, 'defaultSetup');
        $previous = $property->getValue();
        $property->setValue(null, $this->setup);
        try {
            $cache = new FusionCacheMetrics();
            $cache->recordHit('/first/path');
            $cache->recordHit('/second/path');
            $cache->recordMiss('/third/path');
        } finally {
            $property->setValue(null, $previous);
        }
        $this->shutdown();
        $payload = new ExportMetricsServiceRequest();
        $payload->mergeFromString((string) $this->requests['/v1/metrics'][0]->getBody());
        $metrics = $payload->getResourceMetrics()[0]->getScopeMetrics()[0]->getMetrics();
        self::assertCount(2, $metrics);
        $counts = [];
        foreach ($metrics as $metric) {
            $points = $metric->getSum()->getDataPoints();
            self::assertCount(1, $points);
            self::assertCount(0, $points[0]->getAttributes());
            $counts[$metric->getName()] = $points[0]->getAsInt();
        }
        self::assertEquals(['fusion.cache.hit' => 2, 'fusion.cache.miss' => 1], $counts);
    }

    private function createSetup(array $options = []): void
    {
        $this->setup = new OpenTelemetrySetup(Configuration::fromArray(array_replace_recursive([
            'traces' => [
                // Match the values Flow resolves from the package's %env:...% settings.
                'sampler' => ['name' => getenv('OTEL_TRACES_SAMPLER'), 'arg' => getenv('OTEL_TRACES_SAMPLER_ARG')],
                'transport' => ['uri' => 'http://collector.test:4318/v1/traces'],
            ],
            'logs' => ['transport' => ['uri' => 'http://collector.test:4318/v1/logs']],
            'metrics' => ['transport' => ['uri' => 'http://collector.test:4318/v1/metrics']],
            'service' => ['name' => 'test', 'version' => ''],
        ], $options)), new ApplicationContext('Production/Docker'), ['neos.flow.runlevel' => 'runtime']);
    }

    private function shutdown(): void
    {
        (new \ReflectionMethod(OpenTelemetrySetup::class, 'shutdown'))->invoke($this->setup);
    }

    private function env(string $name, string $value): void
    {
        $this->environment[$name] ??= [getenv($name), $_SERVER[$name] ?? null];
        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
    }

    private function attributes(iterable $attributes): array
    {
        $values = [];
        foreach ($attributes as $attribute) {
            $values[$attribute->getKey()] = $attribute->getValue()->getStringValue();
        }
        return $values;
    }
}
