<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes;

#[Flow\Scope("singleton")]
#[Flow\Aspect]
class RepositoryAspect
{
    use SpanAspectTrait;

    /**
     * @Flow\Around("within(Neos\Flow\Persistence\Repository) && method(public .*->find.*(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.repository)")
     */
    public function repositoryMethods(JoinPointInterface $joinPoint): mixed
    {
        $className = $joinPoint->getClassName();
        $methodName = $joinPoint->getMethodName();

        return $this->spanAround(
            joinPoint: $joinPoint,
            spanName: $this->createSpanName($joinPoint),
            spanKind: SpanKind::KIND_INTERNAL,
            attributes: [
                Attributes\CodeAttributes::CODE_FUNCTION_NAME => $methodName,
                "code.class.name" => $className,
            ]
        );
    }
}
