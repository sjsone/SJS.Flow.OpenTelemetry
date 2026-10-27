<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;
use SJS\Flow\OpenTelemetry\Tracing\TraceContext;
use SJS\Flow\OpenTelemetry\Tracing\ExceptionRecorder;

#[Flow\Scope("singleton")]
#[Flow\Aspect]
class CliDispatchAspect
{
    /**
     * @Flow\Around("method(Neos\Flow\Cli\Dispatcher->dispatch(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.commandDispatcher)")
     */
    public function traceCliDispatcherDispatch(JoinPointInterface $joinPoint): mixed
    {
        $request = $joinPoint->getMethodArgument('request');
        $response = $joinPoint->getMethodArgument('response');

        $command = $request->getCommand()->getCommandIdentifier();

        $setup = OpenTelemetryManager::getDefaultSetup();

        $span = $setup->startNewSpanOfKind("CLI $command", SpanKind::KIND_INTERNAL, [
            'process.executable.name' => basename(PHP_BINARY)
        ], TraceContext::extractFromEnvironment());

        $failed = false;
        try {
            $result = $joinPoint->getAdviceChain()->proceed($joinPoint);
            $span->setAttribute('process.exit.code', $response->getExitCode());
            if ($response->getExitCode() !== 0) {
                $span->setStatus(StatusCode::STATUS_ERROR);
            }
            return $result;
        } catch (\Throwable $exception) {
            $failed = true;
            $span->setAttribute('process.exit.code', 1);
            ExceptionRecorder::recordFailure($span, $exception, root: true);
            $setup->deferEndSpan($span);
            throw $exception;
        } finally {
            if (!$failed) {
                $setup->endSpan($span);
                $setup->flushMetrics();
            }
        }
    }
}
