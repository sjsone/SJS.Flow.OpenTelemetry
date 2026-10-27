<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Setup;

use OpenTelemetry\Contrib\Otlp\ContentTypes;
use PHPUnit\Framework\TestCase;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration\SamplerName;

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
    public function testSharedSettingsDoNotOverrideSignalConfiguration(string $key): void
    {
        $configuration = Configuration::fromArray([
            $key => [],
            'traces' => ['transport' => ['uri' => 'https://traces.test/custom']],
            'service' => ['name' => 'test'],
        ]);
        self::assertSame('https://traces.test/custom', $configuration->traces->transport->uri);
        self::assertSame('http://localhost:4318/v1/logs', $configuration->logs->transport->uri);
        self::assertSame('http://localhost:4318/v1/metrics', $configuration->metrics->transport->uri);
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

    /** @dataProvider invalidTransportLimits */
    public function testInvalidTransportLimitsAreRejected(string $key, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('transport.' . $key);
        Configuration::fromArray([
            'traces' => ['transport' => [$key => $value]],
            'service' => ['name' => 'test'],
        ]);
    }

    public static function invalidTransportLimits(): iterable
    {
        foreach ([-1, 0, INF, NAN, false, 'invalid'] as $value) {
            yield ['timeout', $value];
        }
        foreach ([-1, 1.5, false, 'invalid'] as $value) {
            yield ['maxRetries', $value];
        }
    }

    /** @dataProvider invalidSamplers */
    public function testInvalidSamplersAreRejected(array $sampler): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('traces.sampler.');
        Configuration::fromArray(['traces' => ['sampler' => $sampler], 'service' => ['name' => 'test']]);
    }

    public static function invalidSamplers(): iterable
    {
        yield [['name' => 'unknown']];
        yield [['name' => true]];
        foreach ([-0.1, 1.1, INF, NAN, true, 'invalid'] as $arg) {
            yield [['name' => 'traceidratio', 'arg' => $arg]];
        }
    }

    /** @dataProvider defaultSamplerSettings */
    public function testMissingSamplerValuesDefaultToAlwaysOn(array $sampler): void
    {
        $configuration = Configuration::fromArray(['traces' => ['sampler' => $sampler], 'service' => ['name' => 'test']]);
        self::assertSame(SamplerName::AlwaysOn, $configuration->traces->sampler->name);
        self::assertSame(1.0, $configuration->traces->sampler->arg);
    }

    public static function defaultSamplerSettings(): iterable
    {
        yield 'omitted' => [[]];
        yield 'unset Flow environment variables' => [['name' => false, 'arg' => false]];
        yield 'empty environment variables' => [['name' => '', 'arg' => '']];
        yield 'explicit nulls' => [['name' => null, 'arg' => null]];
    }

    private function env(string $name, string $value): void
    {
        $this->environment[$name] ??= [getenv($name), $_SERVER[$name] ?? null];
        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
    }
}
