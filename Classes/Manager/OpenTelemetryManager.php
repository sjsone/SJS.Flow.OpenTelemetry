<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Manager;


use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\HttpRequestHandlerInterface;
use OpenTelemetry\SemConv\Attributes\ServerAttributes;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;
use Neos\Flow\Annotations as Flow;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;


#[Flow\Scope("singleton")]
class OpenTelemetryManager
{
    protected static ?OpenTelemetrySetup $defaultSetup = null;

    protected static Bootstrap $bootstrap;

    public static function setBootstrap(Bootstrap $bootstrap)
    {
        self::$bootstrap = $bootstrap;
    }

    public static function getDefaultSetup(): OpenTelemetrySetup
    {
        if (!isset(self::$defaultSetup)) {
            self::createAndSetDefaultSetup();
        }

        return self::$defaultSetup;
    }

    protected static function createAndSetDefaultSetup()
    {
        self::$defaultSetup = self::createSetup("default");
        return self::$defaultSetup;
    }

    public static function createSetup(string $name): OpenTelemetrySetup
    {
        $configurationManager = self::$bootstrap->getEarlyInstance(ConfigurationManager::class);

        $configurationPath = "SJS.Flow.OpenTelemetry.setup.$name";
        $configurationArray = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $configurationPath);
        if ($configurationArray === null || !\is_array($configurationArray)) {
            throw new \InvalidArgumentException("Could not find a suitable configuration at: $configurationPath");
        }

        $setupClass = $configurationArray["class"] ?? null;
        if (
            !\is_string($setupClass)
            || !\is_a($setupClass, OpenTelemetrySetup::class, true)
            || !(new \ReflectionClass($setupClass))->isInstantiable()
        ) {
            $openTelemetrySetupClass = OpenTelemetrySetup::class;
            throw new \InvalidArgumentException("Configuration at '$configurationPath.class' must name an instantiable class extending '$openTelemetrySetupClass'");
        }

        $context = self::$bootstrap->getContext();

        $runLevel = self::$bootstrap->isCompiletimeCommand(isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '') ? Bootstrap::RUNLEVEL_COMPILETIME : Bootstrap::RUNLEVEL_RUNTIME;

        $additionalAttributes = ['neos.flow.runlevel' => $runLevel];
        $requestHandler = self::$bootstrap->getActiveRequestHandler();
        if ($requestHandler instanceof HttpRequestHandlerInterface) {
            $request = $requestHandler->getHttpRequest();
            if ($request->getUri()->getHost() !== '') {
                $additionalAttributes[ServerAttributes::SERVER_ADDRESS] = $request->getUri()->getHost();
            }
        }

        return new $setupClass(
            configuration: Configuration::fromArray($configurationArray),
            context: $context,
            additionalAttributes: $additionalAttributes
        );
    }
}
