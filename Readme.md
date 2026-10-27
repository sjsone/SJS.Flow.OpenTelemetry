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
          environment: "%env:OTEL_DEPLOYMENT_ENVIRONMENT%"
          traces:
            sampler:
              name: "%env:OTEL_TRACES_SAMPLER%"
              arg: "%env:OTEL_TRACES_SAMPLER_ARG%"
            transport:
              uri: "%env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT%"
              contentType: application/x-protobuf
              headers: []
              timeout: 10.0
              maxRetries: 3
          logs:
            transport:
              uri: "%env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT%"
              contentType: application/x-protobuf
              headers: []
              timeout: 10.0
              maxRetries: 3
          metrics:
            transport:
              uri: "%env:OTEL_EXPORTER_OTLP_METRICS_ENDPOINT%"
              contentType: application/x-protobuf
              headers: []
              timeout: 10.0
              maxRetries: 3
          service:
            name: "%env:OTEL_SERVICE_NAME%"
            version: "%env:OTEL_SERVICE_VERSION%"
```

`contentType` accepts `application/x-protobuf` (default) or `application/json`.
Each signal's `transport.timeout` sets the HTTP timeout in seconds (default `10.0`).
`transport.maxRetries` sets the number of retries after the initial attempt (default `3`; `0` disables retries).
For a collector on the same host, `timeout: 2.0` and `maxRetries: 1` can limit request shutdown delays if the collector is unavailable.
`transport.uri` is the complete signal endpoint and is used as-is: for example, `http://otelcol:4318/v1/traces`. No signal path is appended to an explicitly configured URI.

If the URI is empty or its environment variable is unset, the signal uses `OTEL_EXPORTER_OTLP_TRACES_ENDPOINT`, `OTEL_EXPORTER_OTLP_LOGS_ENDPOINT` or `OTEL_EXPORTER_OTLP_METRICS_ENDPOINT`.

If that is also unset, it derives the signal endpoint from `OTEL_EXPORTER_OTLP_ENDPOINT`, adding `/v1/traces`, `/v1/logs` or `/v1/metrics`.
The final fallback is `http://localhost:4318` with the corresponding signal path.

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

### Exception events: opt in to logs

OpenTelemetry is [deprecating the Span Events API](https://opentelemetry.io/blog/2026/deprecating-span-events/) in favor of named, trace-correlated logs.

Existing span-event behavior remains the default in this package.

To test or adopt the [standard exception migration](https://opentelemetry.io/docs/specs/semconv/exceptions/exceptions-spans/), set:

```sh
OTEL_SEMCONV_EXCEPTION_SIGNAL_OPT_IN=logs
```

| Value      | Exception reporting                                                       |
| ---------- | ------------------------------------------------------------------------- |
| Unset      | One `exception` span event per exception per trace (existing behavior)    |
| `logs`     | One structured log event per exception per trace; no exception span event |
| `logs/dup` | Both the structured log event and the existing exception span event       |
