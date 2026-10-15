<?php

namespace SJS\Flow\OpenTelemetry;

use Neos\Flow\Core\Booting\Sequence;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;
use SJS\Flow\OpenTelemetry\Tracing\CompileTimeCommandTracing;
use SJS\Flow\OpenTelemetry\Tracing\TraceContext;

class Package extends BasePackage
{
    public function boot(Bootstrap $bootstrap)
    {
        OpenTelemetryManager::setBootstrap($bootstrap);

        $command = $_SERVER['argv'][1] ?? '';
        if (!TraceContext::isCli() || !$bootstrap->isCompiletimeCommand($command)) {
            return;
        }

        // Compiletime commands run before AOP proxies are available.
        $tracing = new CompileTimeCommandTracing($command);
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        $dispatcher->connect(Sequence::class, 'afterInvokeStep', $tracing, 'afterInvokeStep', false);
        $dispatcher->connect(Bootstrap::class, 'bootstrapShuttingDown', $tracing, 'finish', false);
    }
}
