<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Setup;

use Neos\Flow\Core\ApplicationContext;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\Context\ContextInterface;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\SpanExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScopeFactory;
use OpenTelemetry\SDK\Logs\LogRecordLimitsBuilder;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\Sampler\AlwaysOnSampler;
use OpenTelemetry\SDK\Trace\Sampler\ParentBased;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\Attributes\ServiceAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\DeploymentIncubatingAttributes;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;
use SJS\Flow\OpenTelemetry\Tracing\TraceContext;
use Neos\Flow\Annotations as Flow;
use OpenTelemetry\API\Trace\SpanKind;

class OpenTelemetrySetup
{
    /**
     * @var array<array{scope: ScopeInterface, span: SpanInterface}>
     */
    protected array $scopesWithSpans = [];

    public readonly TracerInterface $tracer;

    public readonly SpanExporterInterface $spanExporter;

    public readonly TransportInterface $spanTransport;

    public readonly TransportInterface $loggerTransport;

    public readonly LoggerInterface $logger;

    public readonly MeterProviderInterface $meterProvider;

    public readonly MeterInterface $meter;
    /** @var array<int, true> */
    protected array $deferredSpans = [];

    protected bool $shutDown = false;

    public function __construct(
        protected readonly Configuration $configuration,
        protected readonly ApplicationContext $context,
        protected readonly array $additionalAttributes = []
    ) {
        $attributes = [
            ServiceAttributes::SERVICE_NAME => $configuration->service->name,
            ServiceAttributes::SERVICE_VERSION => $configuration->service->version,
            DeploymentIncubatingAttributes::DEPLOYMENT_ENVIRONMENT_NAME => (string) $context,
            ...$this->additionalAttributes
        ];

        $resource = ResourceInfoFactory::emptyResource()->merge(ResourceInfo::create(Attributes::create($attributes)));

        $this->spanTransport = $this->createSpanTransport($configuration);
        $this->spanExporter = new SpanExporter($this->spanTransport);

        $tracerProvider = TracerProvider::builder()
            ->addSpanProcessor(
                new SimpleSpanProcessor($this->spanExporter)
            )
            ->setResource($resource)
            ->setSampler(new ParentBased(new AlwaysOnSampler()))
            ->build();

        $this->tracer = $tracerProvider->getTracer('SJS.Flow.OpenTelemetry');

        $this->loggerTransport = $this->createLoggerTransport($configuration);
        $logRecordExporter = $this->createLogRecordExporter($this->loggerTransport);
        $loggerProvider = $this->createLoggerProvider($logRecordExporter, $resource);

        $this->logger = $loggerProvider->getLogger('SJS.Flow.OpenTelemetry');

        $this->meterProvider = $this->createMeterProvider($resource);
        $this->meter = $this->meterProvider->getMeter('SJS.Flow.OpenTelemetry');

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setLoggerProvider($loggerProvider)
            ->setMeterProvider($this->meterProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

        register_shutdown_function($this->shutdown(...));
    }

    protected function createLoggerTransport(Configuration $configuration): TransportInterface
    {
        $uri = $configuration->uri;
        $transportFactory = new OtlpHttpTransportFactory();
        return $transportFactory->create("$uri/v1/logs", ContentTypes::JSON);
    }

    protected function createSpanTransport(Configuration $configuration): TransportInterface
    {
        $uri = $configuration->uri;
        $transportFactory = new OtlpHttpTransportFactory();
        return $transportFactory->create("$uri/v1/traces", ContentTypes::JSON);
    }

    protected function createLogRecordExporter(TransportInterface $transport): LogRecordExporterInterface
    {
        return new LogsExporter($transport);
    }

    protected function createLoggerProvider(LogRecordExporterInterface $exporter, ResourceInfo $resourceInfo): LoggerProviderInterface
    {
        $processor = new SimpleLogRecordProcessor(
            exporter: $exporter
        );

        $attributesFactory = (new LogRecordLimitsBuilder())->build()->getAttributeFactory();

        $instrumentationScopeFactory = new InstrumentationScopeFactory(
            attributesFactory: $attributesFactory
        );

        return new LoggerProvider(
            processor: $processor,
            instrumentationScopeFactory: $instrumentationScopeFactory,
            resource: $resourceInfo
        );
    }

    protected function createMetricTransport(Configuration $configuration): TransportInterface
    {
        $uri = $configuration->uri;
        $transportFactory = new OtlpHttpTransportFactory();
        return $transportFactory->create("$uri/v1/metrics", ContentTypes::JSON);
    }

    protected function createMeterProvider(ResourceInfo $resource): MeterProviderInterface
    {
        $metricTransport = $this->createMetricTransport($this->configuration);
        $metricExporter = new \OpenTelemetry\Contrib\Otlp\MetricExporter($metricTransport);

        $reader = new ExportingReader($metricExporter);

        return MeterProvider::builder()
            ->setResource($resource)
            ->addReader($reader)
            ->build();
    }

    /**
     * @param iterable<non-empty-string, bool|int|float|string|array|null> $attributes
     * @psalm-param SpanKind::KIND_* $spanKind
     */
    public function startNewSpanOfKind(string $name, int $spanKind, ?iterable $attributes = null, ?ContextInterface $parentContext = null): SpanInterface
    {
        if (empty($name)) {
            throw new \Exception("span requires a non-empty-string");
        }

        $span = $this->tracer->spanBuilder(spanName: $name)
            ->setSpanKind($spanKind)
            ->setParent($parentContext)
            ->setAttributes([...$this->additionalAttributes, ...$attributes ?? []])
            ->startSpan();

        $this->scopesWithSpans[] = [
            "scope" => TraceContext::activate($span, $parentContext),
            "span" => $span
        ];

        return $span;
    }

    public function endSpan(SpanInterface $spanToEnd)
    {
        $scopeWithSpan = \array_pop($this->scopesWithSpans);
        if ($scopeWithSpan === null) {
            throw new \Exception("Could not end span.\nEvery span in the stack already ended.");
        }
    /** Keep failed HTTP requests active while Flow logs the exception and renders its error page. */
    public function deferEndSpan(SpanInterface $span): void
    {
        $this->deferredSpans[spl_object_id($span)] = true;
    }

    public function flushMetrics(): void
    {
        $this->meterProvider->forceFlush();
    }

    /**
     * Fallback for hard exits and scopes cleanup
     */
    protected function shutdown(): void
    {
        if ($this->shutDown) {
            return;
        }
        $this->shutDown = true;
        while (($scopeWithSpan = \array_pop($this->scopesWithSpans)) !== null) {
            ["scope" => $scope, "span" => $span] = $scopeWithSpan;
            if ($span->isRecording() && !isset($this->deferredSpans[spl_object_id($span)])) {
                $span->addEvent("span.forcefullyClosed");
            }
            $scope->detach();
            $span->end();
        }

        $this->deferredSpans = [];
        // Providers must stay alive until ALL spans have ended
        $this->meterProvider->shutdown();
        $this->tracerProvider?->shutdown();
        $this->loggerProvider?->shutdown();
    }
}
