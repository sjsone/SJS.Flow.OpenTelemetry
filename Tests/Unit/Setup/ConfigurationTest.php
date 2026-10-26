<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Setup;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use PHPUnit\Framework\TestCase;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;

final class ConfigurationTest extends TestCase
{
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['OTEL_EXPORTER_OTLP_ENDPOINT', 'OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', 'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT', 'OTEL_EXPORTER_OTLP_METRICS_ENDPOINT'] as $name) {
            $this->env($name, '');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => [$value, $server]) {
            putenv($value === false ? $name : $name . '=' . $value);
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
        }
    }

    public function testSettingsOverrideSignalEnvironmentAndCommonEnvironmentIsOnlyAFallback(): void
    {
        $this->env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://collector:4318/prefix/');
        $this->env('OTEL_EXPORTER_OTLP_TRACES_ENDPOINT', 'https://environment-traces.test/export');
        $this->env('OTEL_EXPORTER_OTLP_LOGS_ENDPOINT', 'https://environment-logs.test/ingest');

        $configuration = Configuration::fromArray([
            'traces' => ['transport' => ['uri' => 'https://configured-traces.test/export']],
            'logs' => ['transport' => ['uri' => false]],
            'metrics' => ['transport' => ['uri' => '']],
            'service' => ['name' => 'test'],
        ]);

        self::assertSame('https://configured-traces.test/export', $configuration->traces->transport->uri);
        self::assertSame('https://environment-logs.test/ingest', $configuration->logs->transport->uri);
        self::assertSame('http://collector:4318/prefix/v1/metrics', $configuration->metrics->transport->uri);
    }

    public function testUnconfiguredSignalsUseSdkDefaultsAndNeverInheritAnotherSignal(): void
    {
        $configuration = Configuration::fromArray([
            'traces' => ['transport' => [
                'uri' => 'https://traces.test/custom',
                'contentType' => ContentTypes::JSON,
                'headers' => ['Authorization' => 'trace-only credential'],
            ]],
            'service' => ['name' => 'test'],
        ]);

        foreach (['logs', 'metrics'] as $signal) {
            self::assertSame('http://localhost:4318/v1/' . $signal, $configuration->$signal->transport->uri);
            self::assertSame(ContentTypes::PROTOBUF, $configuration->$signal->transport->contentType);
            self::assertSame([], $configuration->$signal->transport->headers);
        }
    }

    public function testEachSignalEndpointEnvironmentVariableIsUsedAsIs(): void
    {
        foreach (['traces', 'logs', 'metrics'] as $signal) {
            $this->env('OTEL_EXPORTER_OTLP_' . strtoupper($signal) . '_ENDPOINT', 'https://' . $signal . '.test/');
        }
        $configuration = Configuration::fromArray(['service' => ['name' => 'test']]);
        foreach (['traces', 'logs', 'metrics'] as $signal) {
            self::assertSame('https://' . $signal . '.test/', $configuration->$signal->transport->uri);
        }
    }

    /** @dataProvider sharedConfigurationKeys */
    public function testRemovedSharedSettingsProduceAnActionableMigrationError(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Configure traces.transport, logs.transport and metrics.transport');
        Configuration::fromArray([$key => [], 'service' => ['name' => 'test']]);
    }

    public static function sharedConfigurationKeys(): iterable
    {
        foreach (['transport', 'uri', 'contentType', 'headers'] as $key) {
            yield $key => [$key];
        }
    }

    /** @dataProvider invalidContentTypes */
    public function testInvalidTransportContentTypesAreRejected(string $signal, mixed $contentType): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport.contentType');
        Configuration::fromArray([
            $signal => ['transport' => ['uri' => 'http://collector:4318/v1/' . $signal, 'contentType' => $contentType]],
            'service' => ['name' => 'test'],
        ]);
    }

    public static function invalidContentTypes(): iterable
    {
        foreach (['traces', 'logs', 'metrics'] as $signal) {
            yield "$signal unsupported MIME type" => [$signal, 'text/plain'];
            yield "$signal protocol instead of MIME type" => [$signal, 'http/protobuf'];
            yield "$signal invalid type" => [$signal, false];
        }
    }

    private function env(string $name, string $value): void
    {
        $this->environment[$name] ??= [getenv($name), $_SERVER[$name] ?? null];
        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
    }
}
