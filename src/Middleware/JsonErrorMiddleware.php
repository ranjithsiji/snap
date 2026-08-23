<?php

declare(strict_types=1);

namespace JuryTool\Middleware;

use JuryTool\Support\DomainException;
use JuryTool\Support\Json;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * Renders exceptions as JSON.
 *
 * DomainException carries a message meant for the user and its own status.
 * Anything else is logged in full and reported as an opaque 500, so that
 * stack traces and SQL never leak to a client in production.
 */
class JsonErrorMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        try {
            return $handler->handle($request);
        } catch (DomainException $e) {
            return $this->render($e->getStatus(), $e->getMessage());
        } catch (HttpNotFoundException) {
            return $this->render(404, 'Not found.');
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->render(
                500,
                $this->debug ? $e->getMessage() : 'An unexpected error occurred.',
                $this->debug ? ['exception' => $e::class, 'file' => $e->getFile(), 'line' => $e->getLine()] : [],
            );
        }
    }

    /** @param array<string, mixed> $extra */
    private function render(int $status, string $message, array $extra = []): Response
    {
        return Json::write(
            $this->responseFactory->createResponse($status),
            ['error' => ['status' => $status, 'message' => $message] + $extra],
            $status,
        );
    }
}
