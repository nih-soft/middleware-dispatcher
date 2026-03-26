<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher;

use Fiber;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final readonly class MiddlewareDispatcher implements RequestHandlerInterface
{
    /**
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public function __construct(
        private ContainerInterface      $container,
        private array                   $middlewares,
        private RequestHandlerInterface $finalHandler,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $stack = [];
        $currentRequest = $request;
        $nextIndex = 0;
        $response = null;
        $throwable = null;

        while (true) {
            while ($response === null && $throwable === null) {
                if ($nextIndex >= count($this->middlewares)) {
                    try {
                        $response = $this->finalHandler->handle($currentRequest);
                    } catch (Throwable $caught) {
                        $throwable = $caught;
                    }

                    break;
                }

                $index = $nextIndex;
                $middleware = $this->middlewares[$index];
                $currentMiddleware = $this->resolveMiddleware($middleware);
                $fiber = new Fiber(static function (ServerRequestInterface $request) use ($currentMiddleware): ResponseInterface {
                    $next = new class implements RequestHandlerInterface {
                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            /** @var ResponseInterface $response */
                            $response = Fiber::suspend($request);

                            return $response;
                        }
                    };

                    return $currentMiddleware->process($request, $next);
                });

                try {
                    $yielded = $fiber->start($currentRequest);
                } catch (Throwable $caught) {
                    $throwable = $caught;
                    break;
                }

                if ($fiber->isSuspended()) {
                    $stack[] = [
                        'index' => $index,
                        'fiber' => $fiber,
                    ];

                    if (!$yielded instanceof ServerRequestInterface) {
                        throw new RuntimeException('Middleware yielded a non-request value.');
                    }

                    $currentRequest = $yielded;
                    $nextIndex = $index + 1;
                    continue;
                }

                $response = $fiber->getReturn();
            }

            while ($stack !== []) {
                /** @var array{index: int, fiber: Fiber} $frame */
                $frame = array_pop($stack);
                $fiber = $frame['fiber'];

                if ($fiber->isSuspended()) {
                    try {
                        $yielded = $throwable === null
                            ? $fiber->resume($response)
                            : $fiber->throw($throwable);
                    } catch (Throwable $caught) {
                        $response = null;
                        $throwable = $caught;
                        continue;
                    }

                    if ($fiber->isSuspended()) {
                        if (!$yielded instanceof ServerRequestInterface) {
                            throw new RuntimeException('Middleware yielded a non-request value.');
                        }

                        $stack[] = $frame;
                        $currentRequest = $yielded;
                        $nextIndex = $frame['index'] + 1;
                        $response = null;
                        $throwable = null;
                        continue 2;
                    }

                    $response = $fiber->getReturn();
                    $throwable = null;
                    continue;
                }

                if ($fiber->isTerminated()) {
                    $response = $fiber->getReturn();
                    $throwable = null;
                    continue;
                }

                throw new RuntimeException('Fiber is in an unexpected state.');
            }

            if ($throwable instanceof Throwable) {
                throw $throwable;
            }

            return $response;
        }
    }

    public function resolveMiddleware(MiddlewareInterface|string $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if ($middleware === '') {
            throw new RuntimeException('Middleware class-string must be non-empty.');
        }

        $resolvedMiddleware = $this->container->get($middleware);

        if ($resolvedMiddleware instanceof MiddlewareInterface) {
            return $resolvedMiddleware;
        }

        throw new RuntimeException(sprintf(
            'Resolved middleware %s must implement %s.',
            $middleware,
            MiddlewareInterface::class,
        ));
    }
}
