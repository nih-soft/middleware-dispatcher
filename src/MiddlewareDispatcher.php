<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher;

use Fiber;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

/**
 * Configures and executes a PSR-15 middleware pipeline.
 *
 * Before {@see handle()}, mutation methods change the configured pipeline for
 * subsequent requests. During {@see handle()}, a per-request {@see DispatchControl}
 * may be exposed through the request attribute configured by `$attributeName`.
 */
final class MiddlewareDispatcher extends DispatcherData implements RequestHandlerInterface
{
    private bool $isDispatching = false;

    /**
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     */
    public function __construct(
        private readonly ContainerInterface $container,
        array $middlewares,
        RequestHandlerInterface $finalHandler,
        private readonly string $attributeName = DispatchControl::class,
    ) {
        $this->middlewares = $middlewares;
        $this->finalHandler = $finalHandler;
    }

    /**
     * Executes the configured middleware pipeline for the given request.
     *
     * If `$attributeName` is not empty and the request does not already contain
     * that attribute, the dispatcher stores a {@see DispatchControl} instance there
     * for the duration of the current request.
     *
     * @throws RuntimeException If the same dispatcher instance is entered reentrantly
     *                          or if middleware breaks the dispatcher contract.
     * @throws Throwable
     * @noinspection PhpInconsistentReturnPointsInspection
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->isDispatching) {
            throw new RuntimeException('Reentrant handle() calls on the same dispatcher instance are not supported.');
        }

        $this->isDispatching = true;
        $control = DispatchControl::newInstance($this);

        try {
            $stack = [];
            $currentRequest = $request;

            if ($this->attributeName !== '' && !array_key_exists($this->attributeName, $request->getAttributes())) {
                // Expose the runtime control only when the caller did not
                // pre-populate the attribute with another control object.
                $currentRequest = $request->withAttribute(
                    $this->attributeName,
                    $control,
                );
            }

            $nextIndex = 0;
            $response = null;
            $throwable = null;

            while (true) {
                while ($response === null && $throwable === null) {
                    if ($nextIndex >= count($control->middlewares)) {
                        try {
                            $response = $control->finalHandler->handle($currentRequest);
                        } catch (Throwable $caught) {
                            $throwable = $caught;
                        }

                        break;
                    }

                    $index = $nextIndex;
                    $middleware = $control->middlewares[$index];
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

                    $previousTailStart = $control->tailStart;
                    $previousActiveMiddlewareFiber = $control->activeMiddlewareFiber;
                    $control->tailStart = $index + 1;
                    $control->activeMiddlewareFiber = $fiber;

                    try {
                        // The current middleware may mutate only the tail that
                        // starts after itself.
                        $yielded = $fiber->start($currentRequest);
                    } catch (Throwable $caught) {
                        $throwable = $caught;
                        break;
                    } finally {
                        $control->tailStart = $previousTailStart;
                        $control->activeMiddlewareFiber = $previousActiveMiddlewareFiber;
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

                    if ($control->bypassOuterFiber === $fiber) {
                        return $response;
                    }
                }

                while ($stack !== []) {
                    /** @var array{index: int, fiber: Fiber} $frame */
                    $frame = array_pop($stack);
                    $fiber = $frame['fiber'];

                    if ($fiber->isSuspended()) {
                        $previousTailStart = $control->tailStart;
                        $previousActiveMiddlewareFiber = $control->activeMiddlewareFiber;
                        $control->tailStart = $frame['index'] + 1;
                        $control->activeMiddlewareFiber = $fiber;

                        try {
                            // Unwind resumes suspended middleware with the same
                            // tail boundary as the original forward pass.
                            $yielded = $throwable === null
                                ? $fiber->resume($response)
                                : $fiber->throw($throwable);
                        } catch (Throwable $caught) {
                            $response = null;
                            $throwable = $caught;
                            continue;
                        } finally {
                            $control->tailStart = $previousTailStart;
                            $control->activeMiddlewareFiber = $previousActiveMiddlewareFiber;
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

                        if ($control->bypassOuterFiber === $fiber) {
                            return $response;
                        }

                        continue;
                    }

                    if ($fiber->isTerminated()) {
                        $response = $fiber->getReturn();
                        $throwable = null;

                        if ($control->bypassOuterFiber === $fiber) {
                            return $response;
                        }

                        continue;
                    }

                    throw new RuntimeException('Fiber is in an unexpected state.');
                }

                if ($throwable instanceof Throwable) {
                    throw $throwable;
                }

                return $response;
            }
        } finally {
            $this->isDispatching = false;
        }
    }

    /**
     * Resolves a middleware entry to a concrete middleware instance.
     *
     * Direct middleware objects are returned as-is. Class strings are resolved
     * lazily through the configured PSR-11 container.
     *
     * @throws RuntimeException If the class string is empty or the resolved value
     *                          does not implement {@see MiddlewareInterface}.
     * @throws ContainerExceptionInterface
     */
    private function resolveMiddleware(MiddlewareInterface|string $middleware): MiddlewareInterface
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
