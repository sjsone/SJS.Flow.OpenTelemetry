<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Aspects;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use SJS\Flow\OpenTelemetry\Metrics\FusionCacheMetrics;

#[Flow\Scope("singleton")]
#[Flow\Aspect]
class FusionCacheEventAspect
{
    #[Flow\Inject]
    protected FusionCacheMetrics $fusionCacheMetrics;

    /**
     * @Flow\Around("method(Neos\Fusion\Core\Cache\RuntimeContentCache->preEvaluate(.*)) && setting(SJS.Flow.OpenTelemetry.builtInAspect.enableAdvice.fusionCacheEvents)")
     */
    public function trackCacheEvaluation(JoinPointInterface $joinPoint): array
    {
        $fusionPath = 'unknown';
        $arguments = $joinPoint->getMethodArguments();
        if (isset($arguments['evaluateContext']) && is_array($arguments['evaluateContext'])) {
            $fusionPath = $arguments['evaluateContext']['fusionPath'] ?? 'unknown';
        }

        /** @var array{bool, mixed} $result */
        $result = $joinPoint->getAdviceChain()->proceed($joinPoint);

        $cacheHit = $result[0] ?? false;

        if ($cacheHit) {
            $this->fusionCacheMetrics->recordHit($fusionPath);
        } else {
            $this->fusionCacheMetrics->recordMiss($fusionPath);
        }

        return $result;
    }
}
