<?php

declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Middleware;

use Neos\Flow\Annotations as Flow;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;
use SJS\Flow\OpenTelemetry\Tracing\TraceContext;

#[Flow\Scope("singleton")]
class HttpTracingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        $setup = OpenTelemetryManager::getDefaultSetup();

        $uri = $request->getUri();

        $span = $setup->startNewSpanOfKind('HTTP ' . $request->getMethod(), SpanKind::KIND_SERVER, [
            'http.request.method' => $request->getMethod(),
            'url.path' => $uri->getPath(),
            'url.scheme' => $uri->getScheme(),
            'server.address' => $uri->getHost()
        ], TraceContext::extractFromServerRequest($request));

        try {
            $response = $next->handle($request);
            $span->setAttribute('http.response.status_code', $response->getStatusCode());
            if ($response->getStatusCode() >= 500) {
                $span->setStatus(StatusCode::STATUS_ERROR);
                $span->setAttribute('error.type', (string) $response->getStatusCode());
            }
            return $response;
        } catch (\Throwable $exception) {
            $span->recordException($exception);
            $span->setStatus(StatusCode::STATUS_ERROR);
            $span->setAttribute('error.type', $exception::class);
            throw $exception;
        } finally {
            $setup->endSpan($span);
            $setup->flushMetrics();
        }
    }
}
