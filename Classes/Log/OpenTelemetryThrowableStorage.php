<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Log;

use Neos\Flow\Log\PlainTextFormatter;
use Neos\Flow\Log\ThrowableStorage\FileStorage;
use OpenTelemetry\SDK\Common\Attribute\AttributeValidator;
use OpenTelemetry\API\Trace\Span;
use SJS\Flow\OpenTelemetry\Tracing\ExceptionRecorder;

class OpenTelemetryThrowableStorage extends FileStorage
{
    public function logThrowable(\Throwable $throwable, array $additionalData = []): string
    {
        $span = Span::getCurrent();

        $attributesValidator = new AttributeValidator();

        $prefixedFlowData = [];
        foreach ($additionalData as $key => $value) {
            if ($attributesValidator->validate($value)) {
                $prefixedFlowData["flow.additionalData.$key"] = $value;
            } else {
                $prefixedFlowData["flow.additionalData.$key.plainText"] = (new PlainTextFormatter($value))->format();
            }
        }

        ExceptionRecorder::record(
            $span,
            $throwable,
            attributes: $prefixedFlowData
        );

        return parent::logThrowable($throwable, $additionalData);
    }
}
