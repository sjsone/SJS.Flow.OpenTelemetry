<?php
namespace SJS\Flow\OpenTelemetry\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Log\ThrowableStorageInterface;
use Psr\Log\LoggerInterface;
use Neos\Flow\Log\Utility\LogEnvironment;

class TestCommandController extends CommandController
{
    #[Flow\Inject]
    protected LoggerInterface $systemLogger;

    #[Flow\Inject()]
    protected ThrowableStorageInterface $throwableStorage;

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
