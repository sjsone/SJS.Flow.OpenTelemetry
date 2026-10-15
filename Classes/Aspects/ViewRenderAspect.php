<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes;

#[Flow\Scope("singleton")]
#[Flow\Aspect]
class ViewRenderAspect
{
    use SpanAspectTrait;

    /**
     * @Flow\Around(" within(Neos\Flow\Mvc\View\ViewInterface) && method(.*->render(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.viewInterfaceRender)")
     */
    public function spanViewInterfaceRender(JoinPointInterface $joinPoint): mixed
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
