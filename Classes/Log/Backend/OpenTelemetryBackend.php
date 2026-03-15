<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Log\Backend;

use Neos\Flow\Log\Backend\AbstractBackend;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\SemConv\Attributes\CodeAttributes;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;
use Neos\Flow\Annotations as Flow;

class OpenTelemetryBackend extends AbstractBackend
{
    protected LoggerInterface $logger;

    protected array $attributes = [];

    public function setAttributes(array $attributes)
    {
        $this->attributes = $attributes;
    }

    public function open(): void
    {
        $setup = OpenTelemetryManager::getDefaultSetup();
        $this->logger = $setup->logger;
    }

    public function append(string $message, int $severity = LOG_INFO, $additionalData = null, string|null $packageKey = null, string|null $className = null, string|null $methodName = null): void
    {
        $record = $this->buildLogRecord(
            message: $message,
            severity: $severity,
            additionalData: $additionalData,
            packageKey: $packageKey,
            className: $className,
            methodName: $methodName
        );
        $this->logger->emit(logRecord: $record);
    }

    protected function buildLogRecord(string $message, int $severity = LOG_INFO, $additionalData = null, string|null $packageKey = null, string|null $className = null, string|null $methodName = null): LogRecord
    {
        $record = new LogRecord;

        $record->setBody($message);

        $record->setSeverityNumber($this->mapSeverity($severity));
        $record->setSeverityText($this->mapSeverityText($severity));

        foreach ($additionalData as $key => $value) {
            // TODO: handle toString etc. 
            if (\is_array($value) || \is_object($value)) {
                continue;
            }

            $record->setAttribute("additional.$key", $value);
        }

        if ($methodName) {
            $record->setAttribute(CodeAttributes::CODE_FUNCTION_NAME, $methodName);
        }

        if ($className) {
            $record->setAttribute("code.class.name", $className);
        }

        if (!empty($this->attributes)) {
            $record->setAttributes($this->attributes);
        }

        $record->setTimestamp((new \DateTime())->getTimestamp() * LogRecord::NANOS_PER_SECOND);

        return $record;
    }

    protected function mapSeverity(int $severity): Severity
    {
        return match ($severity) {
            LOG_INFO => Severity::INFO,
            LOG_NOTICE => Severity::INFO2,
            LOG_WARNING => Severity::WARN,
            LOG_ERR => Severity::ERROR,
            LOG_ALERT => Severity::ERROR2,

            default => Severity::INFO,
        };
    }

    protected function mapSeverityText(int $severity): string
    {
        return match ($severity) {
            LOG_INFO => "INFO",
            LOG_NOTICE => "NOTICE",
            LOG_WARNING => "WARNING",
            LOG_ERR => "ERR",
            LOG_ALERT => "ALERT",

            default => "INFO",
        };
    }

    public function close(): void
    {

    }
}