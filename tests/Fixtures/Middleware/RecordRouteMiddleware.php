<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Middleware;

use NIH\MiddlewareDispatcher\Tests\Fixtures\Controllers\Dispatch\DispatchTrace;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final readonly class RecordRouteMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private DispatchTrace $trace,
        private string $label,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $this->trace->add($this->label . ':enter');
        $current = $request->getAttribute('routePipeline');
        $current = is_string($current) && $current !== ''
            ? $current . '>' . $this->label
            : $this->label;

        $request = $request->withAttribute('routePipeline', $current);

        try {
            $response = $handler->handle($request);
        } catch (Throwable $exception) {
            $this->trace->add($this->label . ':exit');

            if ($request->getAttribute('handleExceptionAt') === $this->label) {
                return $this->responseFactory
                    ->createResponse(560)
                    ->withHeader('X-Handled-By', $this->label)
                    ->withHeader('X-Exception-Message', $exception->getMessage())
                    ->withAddedHeader('X-Route-Middleware', $this->label);
            }

            throw $exception;
        }

        $this->trace->add($this->label . ':exit');

        if ($request->getAttribute('throwAt') === $this->label . ':exit') {
            throw new RuntimeException('Thrown at ' . $this->label . ':exit');
        }

        return $response->withAddedHeader('X-Route-Middleware', $this->label);
    }
}
