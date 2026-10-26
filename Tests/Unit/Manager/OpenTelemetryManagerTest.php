<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Tests\Unit\Manager;

use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\ApplicationContext;
use Neos\Flow\Core\Bootstrap;
use PHPUnit\Framework\TestCase;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class OpenTelemetryManagerTest extends TestCase
{
    /** @dataProvider invalidSetupClasses */
    public function testInvalidClassesAreRejectedBeforeConstruction(mixed $class): void
    {
        $this->configure(['class' => $class]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("SJS.Flow.OpenTelemetry.setup.custom.class");
        OpenTelemetryManager::createSetup('custom');
    }

    public static function invalidSetupClasses(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'integer' => [42];
        yield 'array' => [[]];
        yield 'object' => [new \stdClass()];
        yield 'unknown class' => ['MissingOpenTelemetrySetup'];
        yield 'unrelated class' => [\stdClass::class];
        yield 'abstract subclass' => [AbstractSetup::class];
        yield 'private constructor' => [PrivateSetup::class];
    }

    public function testMissingClassSettingIsRejected(): void
    {
        $this->configure([]);
        $this->expectException(\InvalidArgumentException::class);
        OpenTelemetryManager::createSetup('custom');
    }

    public function testCustomSetupReceivesConfigurationContextAndRunLevel(): void
    {
        $manager = $this->configure([
            'class' => CustomSetup::class,
            'uri' => 'http://localhost:4318',
            'service' => ['name' => 'test', 'version' => '1.0'],
            'customSetting' => 'available during construction',
        ]);
        $setup = OpenTelemetryManager::createSetup('custom');
        self::assertInstanceOf(CustomSetup::class, $setup);
        self::assertSame('http://localhost:4318', $setup->receivedConfiguration->uri);
        self::assertSame('test', $setup->receivedConfiguration->service->name);
        self::assertSame($manager, $setup->receivedConfiguration->configurationManager);
        self::assertSame('available during construction', $setup->receivedConfiguration->options['customSetting']);
        self::assertSame('Testing', (string) $setup->receivedContext);
        self::assertSame(['neos.flow.runlevel' => Bootstrap::RUNLEVEL_RUNTIME], $setup->receivedAttributes);
    }

    private function configure(array $configuration): ConfigurationManager
    {
        $manager = $this->createMock(ConfigurationManager::class);
        $manager->expects(self::once())->method('getConfiguration')
            ->with(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'SJS.Flow.OpenTelemetry.setup.custom')
            ->willReturn($configuration);
        $bootstrap = $this->createMock(Bootstrap::class);
        $bootstrap->method('getEarlyInstance')->with(ConfigurationManager::class)->willReturn($manager);
        $bootstrap->method('getContext')->willReturn(new ApplicationContext('Testing'));
        $bootstrap->method('isCompiletimeCommand')->willReturn(false);
        OpenTelemetryManager::setBootstrap($bootstrap);
        return $manager;
    }
}

abstract class AbstractSetup extends OpenTelemetrySetup
{
}

class PrivateSetup extends OpenTelemetrySetup
{
    private function __construct()
    {
    }
}

class CustomSetup extends OpenTelemetrySetup
{
    public function __construct(
        Configuration $configuration,
        ApplicationContext $context,
        array $additionalAttributes = []
    ) {
        $this->receivedConfiguration = $configuration;
        $this->receivedContext = $context;
        $this->receivedAttributes = $additionalAttributes;
    }

    public readonly Configuration $receivedConfiguration;
    public readonly ApplicationContext $receivedContext;
    public readonly array $receivedAttributes;
}
