<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes;

#[Flow\Scope("singleton")]
#[Flow\Aspect]
class CommandControllerAspect
{
    use SpanAspectTrait;

    /**
     * @Flow\Around("method(Neos\Flow\Cli\CommandController->processRequest(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.commandController)")
     */
    public function spanCommandControllerProcessRequest(JoinPointInterface $joinPoint): mixed
    {
        return $this->spanAround(
            joinPoint: $joinPoint,
            spanName: $this->createSpanName($joinPoint),
            spanKind: SpanKind::KIND_INTERNAL,
            attributes: [
                "code.class.name" => $joinPoint->getClassName(),
                Attributes\CodeAttributes::CODE_FUNCTION_NAME => $joinPoint->getMethodName()
            ]
        );
    }

    /**
     * @Flow\Around("within(Neos\Flow\Cli\CommandController) && method(public .*->.*Command(.*)) && !method(.*->initialize.*(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.commandController)")
     */
    public function spanAnyControllerCommand(JoinPointInterface $joinPoint): mixed
    {
        return $this->spanAround(
            joinPoint: $joinPoint,
            spanName: $this->createSpanName($joinPoint),
            spanKind: SpanKind::KIND_INTERNAL,
            attributes: [
                "code.class.name" => $joinPoint->getClassName(),
                Attributes\CodeAttributes::CODE_FUNCTION_NAME => $joinPoint->getMethodName()
            ]
        );
    }
}
