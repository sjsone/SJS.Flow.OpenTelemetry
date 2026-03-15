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
> This package integrates OpenTelemetry into Neos Flow by providing a pre-configured tracer, logger, meter, and automatic tracing aspects.

Just add these environment variables and you are good to go:

- **`OTEL_EXPORTER_OTLP_ENDPOINT`**: The URL to your OTLP-compatible endpoint (e.g., Grafana Tempo, Jaeger)
- **`OTEL_SERVICE_NAME`**: The name of your service
- **`OTEL_SERVICE_VERSION`**: The version of your service

## Functionality

### Logging Backend

A logging backend that sends logs to OpenTelemetry.

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

**Class:** `SJS\Flow\OpenTelemetry\Aspects\SpanAspect`

#### Available Aspects

| Aspect                 | Description                                                        |
| ---------------------- | ------------------------------------------------------------------ |
| commandController      | Traces CommandController execution                                 |
| actionController       | Traces ActionController execution                                  |
| viewInterfaceRender    | Traces View rendering                                              |
| repository             | Traces Repository methods (find*, count*, add*, remove*, etc.)     |
| fusionCacheEvents      | Traces Fusion cache events (warmup, flush, etc.)                   |

#### Example

_`Configuration/Settings.yaml`_

```yaml
SJS:
  Flow:
    OpenTelemetry:
      builtInAspect:
        enableAdvice:
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

### HTTP Response Middleware

Middleware that records HTTP response status as a metric.

**Class:** `SJS\Flow\OpenTelemetry\Middleware\HttpResponseStatusMiddleware`

#### Example

_`Configuration/Settings.Neos.Flow.yaml`_

```yaml
Neos:
  Flow:
    http:
      middlewares:
        'httpResponseStatus':
          position: 'end 50'
          middleware: 'SJS\Flow\OpenTelemetry\Middleware\HttpResponseStatusMiddleware'
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

> [!NOTE]
> This package uses the official [OpenTelemetry PHP SDK](https://github.com/open-telemetry/opentelemetry-php) and supports OTLP export to compatible backends like Grafana Tempo, Jaeger, and OpenTelemetry Collector.
