<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;

use Neos\Flow\Annotations as Flow;
use OpenTelemetry\Contrib\Otlp\ContentTypes;
use OpenTelemetry\API\Signals;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\SDK\Common\Configuration\Configuration as SdkConfiguration;
use OpenTelemetry\SDK\Common\Configuration\Defaults;
use OpenTelemetry\SDK\Common\Configuration\Variables;

#[Flow\Proxy(enabled: false)]
class Transport
{
    protected function __construct(
        public readonly string $uri,
        public readonly string $contentType,
        public readonly array $headers,
    ) {
    }

    public static function fromArray(array $source, string $signal): self
    {
        $contentType = $source['contentType'] ?? ContentTypes::PROTOBUF;
        if (!\in_array($contentType, [ContentTypes::PROTOBUF, ContentTypes::JSON], true)) {
            throw new \InvalidArgumentException('OTLP transport.contentType must be application/x-protobuf or application/json');
        }

        $uri = $source['uri'] ?? '';
        if ($uri === false || $uri === '') {
            $uri = self::resolveEndpoint($signal);
        }

        return new self(
            uri: $uri,
            contentType: $contentType,
            headers: $source['headers'] ?? [],
        );
    }

    private static function resolveEndpoint(string $signal): string
    {
        // TODO: match vs dedicated Signal-Classes like Signal\Trace and Signal\Logs
        $variable = match ($signal) {
            Signals::TRACE => Variables::OTEL_EXPORTER_OTLP_TRACES_ENDPOINT,
            Signals::LOGS => Variables::OTEL_EXPORTER_OTLP_LOGS_ENDPOINT,
            Signals::METRICS => Variables::OTEL_EXPORTER_OTLP_METRICS_ENDPOINT,
            default => throw new \InvalidArgumentException("Unsupported telemetry signal '$signal'"),
        };
        if (SdkConfiguration::has($variable)) {
            return SdkConfiguration::getString($variable);
        }

        $baseUri = SdkConfiguration::has(Variables::OTEL_EXPORTER_OTLP_ENDPOINT)
            ? SdkConfiguration::getString(Variables::OTEL_EXPORTER_OTLP_ENDPOINT)
            : Defaults::OTEL_EXPORTER_OTLP_ENDPOINT;

        return HttpEndpointResolver::create()->resolveToString($baseUri, $signal);
    }
}
