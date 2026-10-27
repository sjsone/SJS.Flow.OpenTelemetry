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

### Authentication and sampling

`OTEL_EXPORTER_OTLP_HEADERS` accepts comma-separated, URL-encoded header values, for example `Authorization=Bearer%20token`.
The SDK uses `OTEL_EXPORTER_OTLP_TRACES_HEADERS`, `OTEL_EXPORTER_OTLP_LOGS_HEADERS` or `OTEL_EXPORTER_OTLP_METRICS_HEADERS` in place of the common headers when set for that signal.
Each signal’s `transport.headers` takes precedence over its environment headers.

The default settings read `OTEL_TRACES_SAMPLER` and `OTEL_TRACES_SAMPLER_ARG` through Flow's `%env:...%` substitution.
When unset or empty, they default to `always_on` and `1.0`. `always_on` records every span, including spans with an incoming unsampled parent.
For example: `parentbased_traceidratio` with argument `0.1` samples 10% of new traces and respects the sampling decision of incoming parents.

Set `SJS.Flow.OpenTelemetry.setup.default.traces.sampler.name` to override the environment.
Supported names are `always_on`, `always_off`, `traceidratio`, `parentbased_always_on`, `parentbased_always_off` and `parentbased_traceidratio`.
For ratio samplers, set `traces.sampler.arg` to a number from `0` to `1` (default `1.0`).
Literal settings override the environment placeholders; null or empty settings use the defaults.

For a custom setup that filters spans after recording (for example, keeping failed or slow root spans), use `traces.sampler.name: always_on` or override `createSampler()` to return `AlwaysOnSampler`.
This records even traces with an incoming unsampled parent, allowing the custom setup to make the export decision.

### Exception status and CLI logging

When an exception propagates through instrumented methods, only the span where it first causes a failure and the HTTP or CLI root span receive ERROR and `error.type`.
Intermediate spans remain unset. Exceptions implementing Flow's `WithHttpStatusInterface` with a 4xx status leave all spans unset, including the origin and CLI root.
Exception details are recorded once per exception per trace, independently of failure status.

Failed HTTP requests and CLI commands keep their root span active through Flow's exception handler so logs and error reporters retain the trace and span IDs.
Shutdown ends these deferred spans before exporting telemetry. A CLI exception sets `process.exit.code` to `1`; commands returning normally record the response's exit code and end their span immediately.

The setup is created before Flow's object manager exists. `Configuration::$configurationManager` provides all settings and Objects configuration to custom setup constructors, but object-manager dependency injection and `Objects.yaml` class substitutions are unavailable at that point.

### Exception events: opt in to logs

OpenTelemetry is [deprecating the Span Events API](https://opentelemetry.io/blog/2026/deprecating-span-events/) in favor of named, trace-correlated logs. Existing span-event behavior remains the default in this package. To test or adopt the [standard exception migration](https://opentelemetry.io/docs/specs/semconv/exceptions/exceptions-spans/), set:

```sh
OTEL_SEMCONV_EXCEPTION_SIGNAL_OPT_IN=logs
```

| Value | Exception reporting |
| ----- | ------------------- |
| Unset or unrecognized | One `exception` span event per exception per trace (existing behavior) |
| `logs` | One structured log event per exception per trace; no exception span event |
| `logs/dup` | Both the structured log event and the existing exception span event |

Structured exception logs have event name `exception`, severity ERROR, and `exception.type`, `exception.message` and `exception.stacktrace` attributes. They use the span where the exception is first recorded for trace/span correlation. Additional data supplied by the throwable storage is retained when that storage first records the exception.

Span status is independent of the chosen signal: the origin and root spans receive ERROR, intermediate and successful parents stay unset, and 4xx exceptions leave all spans unset. In log modes, exception details can also be exported for unsampled traces and when no span is active. Flow's existing exception summary logs and dump files remain enabled; deduplication applies to the structured exception report.

The structured events go through the OpenTelemetry Logs SDK and the configured `logs.transport`, independently of Flow backend severity thresholds. Ensure the logs pipeline is collected when enabling these modes. In OpenObserve, query the logs stream and correlate using `trace_id` and `span_id`; these records are not sent as OTLP trace span events in `logs` mode. The tested OpenObserve version stores the event name in `o2_event_name`, so `o2_event_name = 'exception'` selects these structured reports.

This switch controls exceptions only. The existing `span.forcefullyClosed` lifecycle events remain span events. No SDK log-to-span-event conversion is installed or enabled by this package.

The log-event implementation requires OpenTelemetry PHP API 1.10 and SDK 1.15 or later, as declared in `composer.json`.
