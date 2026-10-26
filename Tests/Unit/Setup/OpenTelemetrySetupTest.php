<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Setup;

use GuzzleHttp\Psr7\Response;
use Neos\Flow\Core\ApplicationContext;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\SpanKind;
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

    public function testEnvironmentAndHeadersCanBeConfiguredAndJsonRemainsAvailable(): void
    {
        $this->createSetup(['environment' => 'staging', 'contentType' => 'application/json', 'headers' => ['authorization' => 'configured']]);
        $span = $this->setup->startNewSpanOfKind('root', SpanKind::KIND_INTERNAL, [], Context::getRoot());
        $this->setup->endSpan($span);
        $this->shutdown();
        $request = $this->requests['/v1/traces'][0];
        self::assertSame('configured', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $traces = new ExportTraceServiceRequest();
        $traces->mergeFromJsonString((string) $request->getBody());
        self::assertSame('staging', $this->attributes($traces->getResourceSpans()[0]->getResource()->getAttributes())['deployment.environment.name']);
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
        $this->setup = new OpenTelemetrySetup(Configuration::fromArray($options + [
            'uri' => 'http://collector.test:4318/',
            'service' => ['name' => 'test', 'version' => ''],
        ]), new ApplicationContext('Production/Docker'), ['neos.flow.runlevel' => 'runtime']);
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
