<h1 align="center">
  Flow OpenTelemetry
</h1>

<h4 align="center">OpenTelemetry integration for Neos Flow with tracing, logging and metrics</h4>

<p align="center">
  <a href="https://packagist.org/packages/sjs/flow-opentelemetry">📦 Packagist</a> •
  <a href="#quick-start">Quick Start</a> •
  <a href="#functionality">Functionality</a>
</p>

## Quick Start

> [!IMPORTANT]
> This package integrates OpenTelemetry into Neos Flow by providing a pre-configured tracer, logger, meter and automatic tracing aspects.

Just add these environment variables and you are good to go:

- **`OTEL_EXPORTER_OTLP_ENDPOINT`**: The URL to your OTLP-compatible endpoint (e.g., Grafana Tempo, Jaeger)
- **`OTEL_SERVICE_NAME`**: The name of your service
- **`OTEL_SERVICE_VERSION`**: The version of your service (optional; omitted when empty)
- **`OTEL_DEPLOYMENT_ENVIRONMENT`**: The environment name (optional; defaults to the lower-case Flow context with `/` replaced by `-`)

### SigNoz

A fast way to get anything out of this package is to use [SigNoz](https://signoz.io/).
For bigger setups Grafana with its extensions is still a better choice but for smaller setups and playing around SigNoz is sufficient.

[Official Guide to Install SigNoz using docker compose](https://signoz.io/docs/install/docker/#install-signoz-using-docker-compose)

### OpenTelemetry Collector

For production environments, it is recommended to use the [OpenTelemetry Collector](https://opentelemetry.io/docs/collector/) (`otelcol`) as a central telemetry processing layer for each project.

Spans and log records use the SDK batch processors and OTLP protobuf by default.

The OpenTelemetry Collector acts as a buffer that is installed ideally locally next to the project.

**Configure the Collector** (`otelcol-config.yaml`):

```yaml
receivers:
  otlp:
    protocols:
      http:
        endpoint: 0.0.0.0:4318

processors:
  batch:

exporters:
  otlp_http/signoz:
    endpoint: http://signoz:4318
    tls:
      insecure: true

service:
  telemetry:
    metrics:
      level: basic
  pipelines:
    traces:
      receivers: [otlp]
      processors: [batch]
      exporters: [otlp_http/signoz]

    metrics:
      receivers: [otlp]
      processors: [batch]
      exporters: [otlp_http/signoz]

    logs:
      receivers: [otlp]
      processors: [batch]
      exporters: [otlp_http/signoz]
```

For more configuration examples, see the [OpenTelemetry Collector documentation](https://opentelemetry.io/docs/collector/configuration/).

## Functionality

### Logging Backend

By default, system and security logs go to the backends `default` and `otel`. That means Flow continues writing its system and security log files. Other integrations can also keep their backends.

Each backend applies its own `severityThreshold`, so the destinations may receive different log levels.

`OpenTelemetryThrowableStorage` extends `FileStorage` so exception dump files, renderer options and Flow reference messages are preserved.

Additional data uses `additional.<key>` attributes.
`Stringable` objects become strings.
Arrays and `JsonSerializable` values become JSON.
Unsupported values are skipped.

**Class:** `SJS\Flow\OpenTelemetry\Log\Backend\OpenTelemetryBackend`

#### Options

| key               | type   |            | description                                                           |
| ----------------- | ------ | ---------- | --------------------------------------------------------------------- |
| severityThreshold | string |            | Minimum log level to send to OpenTelemetry                            |
| attributes        | array  | _optional_ | Key-value pairs of static attributes to attach to each log record     |

#### Example

_`Configuration/Settings.Neos.Flow.log.yaml`_

```yaml
Neos:
  Flow:
    log:
      psr3:
        'Neos\Flow\Log\PsrLoggerFactory':
          systemLogger:
            otel:
              class: SJS\Flow\OpenTelemetry\Log\Backend\OpenTelemetryBackend
              options:
                severityThreshold: "%LOG_NOTICE%"
                attributes:
                  logger: "systemLogger"
          securityLogger:
            otel:
              class: SJS\Flow\OpenTelemetry\Log\Backend\OpenTelemetryBackend
              options:
                severityThreshold: "%LOG_NOTICE%"
                attributes:
                  logger: "securityLogger"
```

#### Disable the default file backends

```yaml
Neos:
  Flow:
    log:
      psr3:
        'Neos\Flow\Log\PsrLoggerFactory':
          systemLogger:
            default:
              class: Neos\Flow\Log\Backend\NullBackend
          securityLogger:
            default:
              class: Neos\Flow\Log\Backend\NullBackend
```

> [!IMPORTANT]
> Exception dump files in `Data/Logs/Exceptions` are managed separately by the throwable storage and remain enabled.

### Tracing Aspects

Built-in AOP aspects that automatically create spans for common Flow operations.

#### Available Aspects

| Aspect                 | Description                                                        |
| ---------------------- | ------------------------------------------------------------------ |
| commandDispatcher      | Creates the runtime CLI entry span                                 |
| commandController      | Traces CommandController execution                                 |
| actionController       | Traces ActionController execution                                  |
| viewInterfaceRender    | Traces View rendering                                              |
| repository             | Traces repository `find*` methods as internal spans                |
| fusionCacheEvents      | Counts Fusion cache hits and misses                                |

#### Built-In Aspect Configuration Example

_`Configuration/Settings.yaml`_

```yaml
SJS:
  Flow:
    OpenTelemetry:
      builtInAspect:
        enableAdvice:
          commandDispatcher: true
          commandController: true
          actionController: true
          viewInterfaceRender: true
          repository: false
          fusionCacheEvents: true
      setup:
        default:
          class: SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup
          uri: "%env:OTEL_EXPORTER_OTLP_ENDPOINT%"
          environment: "%env:OTEL_DEPLOYMENT_ENVIRONMENT%"
          contentType: application/x-protobuf
          headers: []
          service:
            name: "%env:OTEL_SERVICE_NAME%"
            version: "%env:OTEL_SERVICE_VERSION%"
```

### HTTP Tracing Middleware

Middleware that creates the HTTP entry span and records response status and errors.

**Class:** `SJS\Flow\OpenTelemetry\Middleware\HttpTracingMiddleware`

#### HTTP Tracing Example

_`Configuration/Settings.Neos.Flow.yaml`_

```yaml
Neos:
  Flow:
    http:
      middlewares:
        'httpTracing':
          position: 'start 200'
          middleware: 'SJS\Flow\OpenTelemetry\Middleware\HttpTracingMiddleware'
```

### Authentication and sampling

`OTEL_EXPORTER_OTLP_HEADERS` accepts comma-separated, URL-encoded header values, for example `Authorization=Bearer%20token`.
The SDK uses `OTEL_EXPORTER_OTLP_TRACES_HEADERS`, `OTEL_EXPORTER_OTLP_LOGS_HEADERS` or `OTEL_EXPORTER_OTLP_METRICS_HEADERS` in place of the common headers when set for that signal.
Setup headers take precedence over these environment variables.

Sampling follows `OTEL_TRACES_SAMPLER` and `OTEL_TRACES_SAMPLER_ARG`.
For example: `parentbased_traceidratio` with argument `0.1` samples 10% of new traces and respects the sampling decision of incoming parents. The default is `parentbased_always_on`.
