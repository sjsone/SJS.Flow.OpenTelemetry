<?php
namespace SJS\Flow\OpenTelemetry\Command;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Psr\Log\LoggerInterface;
use Neos\Flow\Log\Utility\LogEnvironment;

class TestCommandController extends CommandController
{
    #[Flow\Inject]
    protected LoggerInterface $systemLogger;

    // ./flow test:exception
    public function exceptionCommand()
    {
        throw new \Exception('Testing the Loki client', 6942066669);
    }

    // ./flow test:log
    public function logCommand(string $message = "Test")
    {
        $this->outputLine("Before log");
        $this->systemLogger->notice($message, LogEnvironment::fromMethodName(__METHOD__));
        $this->outputLine("After log");
    }
}