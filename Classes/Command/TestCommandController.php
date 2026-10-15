<?php
namespace SJS\Flow\OpenTelemetry\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\ThrowableStorageInterface;
use Psr\Log\LoggerInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use Neos\Flow\Configuration\ConfigurationManager;
use Neos\Flow\Core\Booting\Scripts;
use OpenTelemetry\API\Trace\Span;

class TestCommandController extends CommandController
{
    #[Flow\Inject]
    protected LoggerInterface $systemLogger;

    #[Flow\Inject()]
    protected ThrowableStorageInterface $throwableStorage;

    #[Flow\Inject]
    protected ConfigurationManager $configurationManager;

    // ./flow test:subprocess
    public function subprocessCommand(string $message = 'Subprocess trace test', bool $async = false): void
    {
        $spanContext = Span::getCurrent()->getContext();
        $this->outputLine('Parent trace ID: %s', [$spanContext->getTraceId()]);
        $settings = $this->configurationManager->getConfiguration(ConfigurationManager::CONFIGURATION_TYPE_SETTINGS, 'Neos.Flow');
        $arguments = ['message' => $message];
        if ($async) {
            Scripts::executeCommandAsync('sjs.flow.opentelemetry:test:log', $settings, $arguments);
        } else {
            Scripts::executeCommand('sjs.flow.opentelemetry:test:log', $settings, true, $arguments);
        }
    }

    // ./flow test:exception
    public function exceptionCommand()
    {
        throw new \Exception('Testing the Loki client', 6942066669);
    }

    // ./flow test:storedexception
    public function storedExceptionCommand()
    {
        $exception = new \RuntimeException('Root exception', 1791398600, new \RuntimeException('Nested message', 1791398607));

        $message = $this->throwableStorage->logThrowable($exception, [
            "myString" => "a",
            "myStringList" => ["a", "b"],
            "myStringIntList" => ["a", 123],
        ]);
        $this->systemLogger->error($message);
    }

    // ./flow test:log
    public function logCommand(string $message = "Test")
    {
        $this->outputLine("Before log");
        $this->systemLogger->notice($message, LogEnvironment::fromMethodName(__METHOD__));
        $this->outputLine("After log");
    }
}
