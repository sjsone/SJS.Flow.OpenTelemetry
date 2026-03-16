<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Setup;

use Neos\Behat\Tests\Behat\FlowContextTrait;
use Neos\Flow\Core\ApplicationContext;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
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
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use OpenTelemetry\SemConv\Attributes\UrlAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\DeploymentIncubatingAttributes;
use OpenTelemetry\SemConv\Incubating\Attributes\ServiceIncubatingAttributes;
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
use Neos\Flow\Annotations as Flow;


class OpenTelemetrySetup
{
    protected SpanInterface $rootSpan;

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

        if (isset($_SERVER['REQUEST_METHOD'])) {
            $attributes[HttpAttributes::HTTP_REQUEST_METHOD] = $_SERVER['REQUEST_METHOD'];
        }
        if (isset($_SERVER['REQUEST_URI'])) {
            $attributes[UrlAttributes::URL_FULL] = $_SERVER['REQUEST_URI'];
        }

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

        $this->tracer = $tracerProvider->getTracer('demo');

        $this->loggerTransport = $this->createLoggerTransport($configuration);
        $logRecordExporter = $this->createLogRecordExporter($this->loggerTransport);
        $loggerProvider = $this->createLoggerProvider($logRecordExporter);

        $this->logger = $loggerProvider->getLogger('demo');

        $this->meterProvider = $this->createMeterProvider($resource);
        $this->meter = $this->meterProvider->getMeter('demo');

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setLoggerProvider($loggerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

        $this->buildRootSpan();
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

    protected function createLoggerProvider(LogRecordExporterInterface $exporter): LoggerProviderInterface
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
            instrumentationScopeFactory: $instrumentationScopeFactory
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

    public function buildRootSpan()
    {
        $spanBuilder = $this->tracer->spanBuilder("root")->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_SERVER);

        $this->rootSpan = $spanBuilder->startSpan();
        $this->rootSpan->setAttributes($this->additionalAttributes);

        $this->scopesWithSpans[] = [
            "scope" => $this->rootSpan->activate(),
            "span" => $this->rootSpan
        ];

        register_shutdown_function($this->shutdown(...));
    }

    public function getRootSpan(): SpanInterface
    {
        return $this->rootSpan;
    }

    /**
     * @param iterable<non-empty-string, bool|int|float|string|array|null> $attributes
     * @psalm-param SpanKind::KIND_* $spanKind
     */
    public function startNewSpanOfKind(string $name, int $spanKind, ?array $attributes = null)
    {
        if (empty($name)) {
            throw new \Exception("span requires a non-empty-string");
        }

        $span = $this->tracer->spanBuilder(spanName: $name)
            ->setSpanKind($spanKind)
            ->startSpan();

        $span->setAttributes(attributes: $this->additionalAttributes);

        if ($attributes !== null) {
            $span->setAttributes(attributes: $attributes);
        }

        $this->scopesWithSpans[] = [
            "scope" => $span->activate(),
            "span" => $span
        ];

        return $span;
    }

    public function endSpan(SpanInterface $spanToEnd)
    {
        $scopeWithSpan = array_pop($this->scopesWithSpans);
        if ($scopeWithSpan === null) {
            throw new \Exception("Could not end span.\nEvery span in the stack already ended.");
        }

        ["scope" => $scope, "span" => $span] = $scopeWithSpan;
        if ($span !== $spanToEnd) {
            array_push($scopeWithSpan, $this->scopesWithSpans);
            throw new \Exception("Could not end span.\nSupplied span to end is not next in stack");
        }

        $scope->detach();
        $spanToEnd->end();
    }


    protected function endRootSpan()
    {
        $this->endSpan($this->rootSpan);
        // Flush metrics to ensure they are exported at the end of the request
        $this->meterProvider->forceFlush();
    }

    protected function shutdown(): void
    {
        $runAway = \count($this->scopesWithSpans) + 10;
        while ($runAway-- && \count($this->scopesWithSpans) > 1) {
            $scopeWithSpan = array_pop($this->scopesWithSpans);
            ["scope" => $scope, "span" => $span] = $scopeWithSpan;

            if ($span->isRecording()) {
                $span->addEvent("span.forcefullyClosed");

                $detachResult = $scope->detach();
                if ($detachResult !== ScopeInterface::DETACHED) {
                    $span->end();
                }
            }
        }

        $this->endRootSpan();
    }
}