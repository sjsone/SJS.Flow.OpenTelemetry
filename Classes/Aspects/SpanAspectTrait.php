<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Aspects;

use Neos\Flow\Aop\JoinPointInterface;
use Neos\Flow\Annotations as Flow;

use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;

trait SpanAspectTrait
{
    protected function createSpanName(JoinPointInterface $joinPoint): string
    {
        $className = $joinPoint->getClassName();
        $methodName = $joinPoint->getMethodName();
        return $this->createSpanNameFromClassAndMethod($className, $methodName);
    }

    protected function createSpanNameFromClassAndMethod(string $className, string $methodName): string
    {
        $shortClassName = (new \ReflectionClass($className))->getShortName();
        return \sprintf('%s::%s', $shortClassName, $methodName);
    }

    /**
     * @param iterable<non-empty-string, bool|int|float|string|array|null> $attributes
     * @psalm-param SpanKind::KIND_* $spanKind
     */
    protected function spanAround(JoinPointInterface $joinPoint, string $spanName, int $spanKind, array $attributes): mixed
    {
        $span = OpenTelemetryManager::getDefaultSetup()->startNewSpanOfKind(
            name: $spanName,
            spanKind: $spanKind,
            attributes: $attributes
        );

        try {
            $result = $joinPoint->getAdviceChain()->proceed($joinPoint);
        } catch (\Throwable $th) {
            $span->recordException($th);
            throw $th;
        } finally {
            OpenTelemetryManager::getDefaultSetup()->endSpan($span);
        }

        return $result;
    }
}