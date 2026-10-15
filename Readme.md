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
- **`OTEL_SERVICE_VERSION`**: The version of your service

### SigNoz

A fast way to get anything out of this package is to use [SigNoz](https://signoz.io/).
For bigger setups Grafana with its extensions is still a better choice but for smaller setups and playing around SigNoz is sufficient.

[Official Guide to Install SigNoz using docker compose](https://signoz.io/docs/install/docker/#install-signoz-using-docker-compose)

### OpenTelemetry Collector

For production environments, it is recommended to use the [OpenTelemetry Collector](https://opentelemetry.io/docs/collector/) (`otelcol`) as a central telemetry processing layer for each project.

Each log, trace/span and metric is sent and may take some time. Sending all of that synchronously over the network can impact performance.
The OpenTelemetry Collector is installed ideally locally next to the project or with a  low-latency high-speed connection in-between.
It acts as a buffer that sends every received data via the specified exporter.

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

HTTP requests and CLI commands create entry spans. HTTP spans record response status and errors. CLI spans record exit codes.
Incoming HTTP trace headers and CLI `TRACEPARENT`/`TRACESTATE` values continue an existing trace. Flow subprocesses inherit the active CLI trace context.

For HTTP requests, `server.address` identifies the request domain in traces, logs and metrics.

### Logging Backend

A logging backend that sends logs to OpenTelemetry.

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
            default:
              class: SJS\Flow\OpenTelemetry\Log\Backend\OpenTelemetryBackend
              options:
                severityThreshold: "%LOG_NOTICE%"
                attributes:
                  logger: "systemLogger"
          securityLogger:
            default:
              class: SJS\Flow\OpenTelemetry\Log\Backend\OpenTelemetryBackend
              options:
                severityThreshold: "%LOG_NOTICE%"
                attributes:
                  logger: "securityLogger"
```

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
          repository: true
          fusionCacheEvents: true
      setup:
        default:
          class: SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup
          uri: "%env:OTEL_EXPORTER_OTLP_ENDPOINT%"
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

### Configuration Reference

_`Configuration/Settings.yaml`_

| Setting                           | Type    | Default | Description                                          |
| --------------------------------- | ------- | ------- | ---------------------------------------------------- |
| `builtInAspect.enableAdvice.*`    | boolean | true    | Enable/disable specific tracing aspects              |
| `setup.default.class`             | string  |         | OpenTelemetrySetup class to use                      |
| `setup.default.uri`               | string  |         | OTLP endpoint URI                                    |
| `setup.default.service.name`      | string  |         | Service name for telemetry data                      |
| `setup.default.service.version`   | string  |         | Service version for telemetry data                   |
