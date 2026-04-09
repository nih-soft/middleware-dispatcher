<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher;

use Fiber;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Shared mutation API for dispatcher configuration and per-request runtime control.
 *
 * The same methods are available before dispatch on {@see DispatchConfig}
 * and during dispatch on {@see DispatchRuntime}, but they affect different state.
 */
class DispatchConfig
{
    /**
     * @var list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    protected array $middlewares = [];

    /**
     * @var RequestHandlerInterface|class-string<RequestHandlerInterface>
     * @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection
     */
    protected RequestHandlerInterface|string $finalHandler;

    /**
     * Tracks the first middleware index that belongs to the currently mutable
     * tail of the active middleware fiber.
     */
    protected int $tailStart = 0;

    protected ?Fiber $bypassOuterFiber = null;

    /**
     * Tracks the dispatcher-managed middleware fiber that is currently allowed
     * to call bypassOuter().
     */
    protected ?Fiber $activeMiddlewareFiber = null;

    /**
     * @param list<MiddlewareInterface|class-string<MiddlewareInterface>> $middlewares
     * @param RequestHandlerInterface|class-string<RequestHandlerInterface> $finalHandler
     */
    public function __construct(
        array $middlewares = [],
        RequestHandlerInterface|string $finalHandler = '',
    ) {
        $this->middlewares = $middlewares;
        $this->finalHandler = $finalHandler;
    }

    protected static function newInstance(mixed ...$args): static
    {
        return new static(...$args);
    }

    /**
     * Appends middleware to the configured pipeline or to the remaining runtime tail.
     *
     * When `$after` is provided, the middleware is inserted after the last matching
     * class found in the currently mutable tail.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface>|list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     * @param class-string<MiddlewareInterface> $after
     */
    final public function append(MiddlewareInterface|string|array $middleware, string $after = ''): void
    {
        if (empty($middleware)) {
            return;
        }

        if ($after === '') {
            if (is_array($middleware)) {
                $this->middlewares = array_merge($this->middlewares, array_values($middleware));
            }
            else {
                $this->middlewares[] = $middleware;
            }

            return;
        }

        $middlewares = is_array($middleware)
            ? array_values($middleware)
            : [$middleware];

        $count = count($this->middlewares);
        $tailStart = min($this->tailStart, $count);
        $insertAt = $count;

        for ($index = $count - 1; $index >= $tailStart; $index--) {
            $currentMiddleware = $this->middlewares[$index];

            if ((is_string($currentMiddleware) ? $currentMiddleware : $currentMiddleware::class) === $after) {
                $insertAt = $index + 1;
                break;
            }
        }

        $this->middlewares = array_merge(
            array_slice($this->middlewares, 0, $insertAt),
            $middlewares,
            array_slice($this->middlewares, $insertAt),
        );
    }

    /**
     * Prepends middleware to the configured pipeline or to the remaining runtime tail.
     *
     * When `$before` is provided, the middleware is inserted before the first matching
     * class found in the currently mutable tail.
     *
     * @param MiddlewareInterface|class-string<MiddlewareInterface>|list<MiddlewareInterface|class-string<MiddlewareInterface>> $middleware
     * @param class-string<MiddlewareInterface> $before
     */
    final public function prepend(MiddlewareInterface|string|array $middleware, string $before = ''): void
    {
        if (empty($middleware)) {
            return;
        }

        $middlewares = is_array($middleware)
            ? array_values($middleware)
            : [$middleware];

        if ($this->tailStart === 0 && $before === '') {
            $this->middlewares = array_merge($middlewares, $this->middlewares);

            return;
        }

        $count = count($this->middlewares);
        $tailStart = min($this->tailStart, $count);
        $insertAt = $tailStart;

        if ($before !== '') {
            for ($index = $tailStart; $index < $count; $index++) {
                $currentMiddleware = $this->middlewares[$index];

                if ((is_string($currentMiddleware) ? $currentMiddleware : $currentMiddleware::class) === $before) {
                    $insertAt = $index;
                    break;
                }
            }
        }

        $this->middlewares = array_merge(
            array_slice($this->middlewares, 0, $insertAt),
            $middlewares,
            array_slice($this->middlewares, $insertAt),
        );
    }

    /**
     * Removes all matching middleware classes from the configured pipeline or mutable runtime tail.
     *
     * @param class-string<MiddlewareInterface> $middlewareClass
     * @return int Number of removed middleware entries.
     */
    final public function remove(string $middlewareClass): int
    {
        $count = count($this->middlewares);
        $tailStart = min($this->tailStart, $count);
        $removed = 0;

        for ($index = $tailStart; $index < $count; $index++) {
            $currentMiddleware = $this->middlewares[$index];

            if ((is_string($currentMiddleware) ? $currentMiddleware : $currentMiddleware::class) !== $middlewareClass) {
                continue;
            }

            unset($this->middlewares[$index]);
            $removed++;
        }

        if ($removed > 0) {
            $this->middlewares = array_values($this->middlewares);
        }

        return $removed;
    }

    /**
     * Replaces the configured final handler or the current request final handler.
     *
     * @param RequestHandlerInterface|class-string<RequestHandlerInterface> $handler
     */
    final public function setFinalHandler(RequestHandlerInterface|string $handler): void
    {
        $this->finalHandler = $handler;
    }
}
