<?php
declare(strict_types=1);

namespace SJS\Flow\OpenTelemetry\Middleware;

use Neos\Flow\Annotations as Flow;
use OpenTelemetry\SemConv\Attributes\HttpAttributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SJS\Flow\OpenTelemetry\Manager\OpenTelemetryManager;

#[Flow\Scope("singleton")]
class HttpResponseStatusMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $next): ResponseInterface
    {
        $response = $next->handle($request);

        $rootSpan = OpenTelemetryManager::getDefaultSetup()->getRootSpan();
        if ($rootSpan->isRecording()) {
            $statusCode = $response->getStatusCode();
            $rootSpan->setAttribute(HttpAttributes::HTTP_RESPONSE_STATUS_CODE, $statusCode);
        }

        return $response;
    }
}
