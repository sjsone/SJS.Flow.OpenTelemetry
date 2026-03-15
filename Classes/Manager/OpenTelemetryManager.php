<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Manager;


use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Bootstrap;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup;
use Neos\Flow\Annotations as Flow;
use SJS\Flow\OpenTelemetry\Setup\OpenTelemetrySetup\Configuration;


#[Flow\Scope("singleton")]
class OpenTelemetryManager
{
    protected static OpenTelemetrySetup $defaultSetup;

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
        if (!($configurationManager instanceof ConfigurationManager)) {
            throw new \Exception("ConfigurationManager not yet available.\nIt is probably too early in the bootstrap process.");
        }

        $configurationPath = "SJS.Flow.OpenTelemetry.setup.$name";
        $configurationArray = $configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, $configurationPath);
        if ($configurationArray === null || !\is_array($configurationArray)) {
            throw new \InvalidArgumentException("Could not find a suitable configuration at: $configurationPath");
        }

        $setupClass = $configurationArray["class"];
        if (!class_exists($setupClass)) {
            // TODO: improve correct class detection
            $openTelemetrySetupClass = OpenTelemetrySetup::class;
            throw new \InvalidArgumentException("Class '$setupClass' must be instance of '$openTelemetrySetupClass'");
        }

        $context = self::$bootstrap->getContext();

        $runLevel = self::$bootstrap->isCompiletimeCommand(isset($_SERVER['argv'][1]) ? $_SERVER['argv'][1] : '') ? Bootstrap::RUNLEVEL_COMPILETIME : Bootstrap::RUNLEVEL_RUNTIME;

        return new $setupClass(
            configuration: Configuration::fromArray($configurationArray),
            context: $context,
            additionalAttributes: [
                "neos.flow.runlevel" => $runLevel
            ]
        );
    }
}