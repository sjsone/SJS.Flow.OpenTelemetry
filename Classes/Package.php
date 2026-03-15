<?php
namespace SJS\Flow\OpenTelemetry;

use Neos\Flow\Core\Booting\Sequence;
use Neos\Flow\Core\Booting\Step;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;

class Package extends BasePackage
{

    /**
     * {@inheritdoc}
     */
    public function boot(Bootstrap $bootstrap)
    {
        OpenTelemetryManager::setBootstrap($bootstrap);


        $isAfterConfiguration = false;
        $currentSpan = null;
        $bootstrap->getSignalSlotDispatcher()->connect(
            Sequence::class,
            'beforeInvokeStep',
            function (Step $step, $runlevel) use (&$currentSpan, $isAfterConfiguration) {
                if (!$isAfterConfiguration) {
                    return;
                }
                if ($currentSpan === null) {
                    $currentSpan = OpenTelemetryManager::getDefaultSetup()->startNewSpan($step->getIdentifier());
                }
            }
        );

        $bootstrap->getSignalSlotDispatcher()->connect(
            Sequence::class,
            'afterInvokeStep',
            function (Step $step, $runlevel) use (&$currentSpan, &$isAfterConfiguration) {
                if ($step->getIdentifier() === "neos.flow:configuration") {
                    $isAfterConfiguration = true;
                }
                if ($currentSpan !== null) {
                    OpenTelemetryManager::getDefaultSetup()->endSpan($currentSpan);
                    $currentSpan = null;
                }
            }
        );
    }
}