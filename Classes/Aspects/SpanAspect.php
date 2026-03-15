<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SemConv\Attributes;

#[Flow\Scope("singleton")]
#[Flow\Aspect]
class SpanAspect
{
    use SpanAspectTrait;

    /**
     * @Flow\Around("method(Neos\Flow\Cli\CommandController->processRequest(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.commandController)")
     */
    public function spanCommandController(JoinPointInterface $joinPoint): mixed
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
     * @Flow\Around("within(Neos\Flow\Cli\CommandController) && method(.*->.*Command(.*)) && !method(.*->initialize.*(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.commandController)")
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

    /**
     * @Flow\Around("method(Neos\Flow\Mvc\Controller\ActionController->processRequest(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.actionController)")
     */
    public function spanActionController(JoinPointInterface $joinPoint): mixed
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

    /**
     * Trace explicit repository methods like findAll(), findByIdentifier(), etc.
     *
     * Uses CLIENT span kind as required by OpenTelemetry semantic conventions
     * for database operations to be recognized by observability platforms.
     *
     * @Flow\Around("within(Neos\Flow\Persistence\Repository) && method(public .*->find.*(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.repository)")
     */
    public function repositoryMethods(JoinPointInterface $joinPoint): mixed
    {
        $className = $joinPoint->getClassName();
        $methodName = $joinPoint->getMethodName();

        return $this->spanAround(
            joinPoint: $joinPoint,
            spanName: $this->createSpanName($joinPoint),
            spanKind: SpanKind::KIND_CLIENT,
            attributes: [
                Attributes\DbAttributes::DB_SYSTEM_NAME => Attributes\DbAttributes::DB_SYSTEM_NAME_VALUE_MARIADB,
                Attributes\DbAttributes::DB_OPERATION_NAME => $methodName,
                Attributes\DbAttributes::DB_COLLECTION_NAME => str_replace("Repository", "", $className),
                Attributes\CodeAttributes::CODE_FUNCTION_NAME => $methodName,
                "code.class.name" => $className,
            ]
        );
    }
}
