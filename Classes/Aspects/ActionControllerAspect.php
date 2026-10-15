<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes;

#[Flow\Scope("singleton")]
#[Flow\Aspect]
class ActionControllerAspect
{
    use SpanAspectTrait;

    /**
     * @Flow\Around("method(Neos\Flow\Mvc\Controller\ActionController->processRequest(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.actionController)")
     */
    public function spanActionControllerProcessRequest(JoinPointInterface $joinPoint): mixed
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
     * @Flow\Around("within(Neos\Flow\Mvc\Controller\ActionController) && method(.*->.*Action(.*)) && !method(.*->initialize.*(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.actionController)")
     */
    public function spanAnyControllerAction(JoinPointInterface $joinPoint): mixed
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
