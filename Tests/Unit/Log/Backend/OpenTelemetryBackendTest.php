<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Log\Backend;

use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LogRecordProcessorInterface;
use OpenTelemetry\SDK\Logs\ReadWriteLogRecord;
use PHPUnit\Framework\TestCase;
use SJS\Flow\OpenTelemetry\Log\Backend\OpenTelemetryBackend;

final class OpenTelemetryBackendTest extends TestCase
{
    public function testAdditionalDataIsConvertedWithoutLosingScalarTypes(): void
    {
        $record = $this->emit([
            'count' => 0,
            'enabled' => false,
            'ratio' => 1.5,
            'empty' => '',
            'stringable' => new class implements \Stringable {
                public function __toString(): string { return 'value'; }
            },
            'enum' => TestValue::Number,
            'unitEnum' => TestFlag::Enabled,
            'array' => ['name' => 'München', 'values' => [1, true]],
            'serializable' => new class implements \JsonSerializable {
                public function jsonSerialize(): mixed { return ['url' => 'https://example.com']; }
            },
        ]);

        self::assertSame('message', $record->getBody());
        self::assertSame([
            'additional.count' => 0,
            'additional.enabled' => false,
            'additional.ratio' => 1.5,
            'additional.empty' => '',
            'additional.stringable' => 'value',
            'additional.enum' => 42,
            'additional.unitEnum' => 'Enabled',
            'additional.array' => '{"name":"München","values":[1,true]}',
            'additional.serializable' => '{"url":"https://example.com"}',
        ], $record->getAttributes()->toArray());
    }

    public function testUnsupportedOrFailingValuesDoNotPreventLogging(): void
    {
        $recursive = [];
        $recursive['self'] = &$recursive;
        $resource = fopen('php://memory', 'r');
        try {
            $record = $this->emit([
                'null' => null,
                'object' => new \stdClass(),
                'resource' => $resource,
                'recursive' => $recursive,
                'stringable' => new class implements \Stringable {
                    public function __toString(): string { throw new \RuntimeException('Cannot stringify'); }
                },
                'serializable' => new class implements \JsonSerializable {
                    public function jsonSerialize(): mixed { throw new \RuntimeException('Cannot serialize'); }
                },
                'valid' => 'kept',
            ]);
            self::assertSame(['additional.valid' => 'kept'], $record->getAttributes()->toArray());
        } finally {
            fclose($resource);
        }
    }

    public function testMissingOrNonIterableAdditionalDataDoesNotProduceWarnings(): void
    {
        foreach ([null, 'text', 42, new \stdClass()] as $data) {
            self::assertSame([], $this->emit($data)->getAttributes()->toArray());
        }
    }

    public function testIterableAdditionalDataIsSupported(): void
    {
        self::assertSame(['additional.key' => 'value'], $this->emit(new \ArrayIterator(['key' => 'value']))->getAttributes()->toArray());
    }

    private function emit(mixed $additionalData): ReadWriteLogRecord
    {
        $record = null;
        $processor = $this->createMock(LogRecordProcessorInterface::class);
        $processor->expects(self::once())->method('onEmit')->willReturnCallback(static function (ReadWriteLogRecord $emitted) use (&$record): void {
            $record = $emitted;
        });
        $provider = LoggerProvider::builder()->addLogRecordProcessor($processor)->build();
        $backend = new OpenTelemetryBackend();
        $logger = new \ReflectionProperty(OpenTelemetryBackend::class, 'logger');
        $logger->setAccessible(true);
        $logger->setValue($backend, $provider->getLogger('test'));
        try {
            $backend->append('message', additionalData: $additionalData);
        } finally {
            $provider->shutdown();
        }

        return $record;
    }
}

enum TestValue: int
{
    case Number = 42;
}

enum TestFlag
{
    case Enabled;
}
