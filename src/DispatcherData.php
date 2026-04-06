<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher;

use Fiber;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Shared mutation API for dispatcher configuration and per-request runtime control.
 *
 * The same methods are available before dispatch on {@see MiddlewareDispatcher}
 * and during dispatch on {@see DispatchControl}, but they affect different state.
 */
abstract class DispatcherData
{
    /**
     * @var list<MiddlewareInterface|class-string<MiddlewareInterface>>
     */
    protected array $middlewares = [];

    /** @noinspection PhpGetterAndSetterCanBeReplacedWithPropertyHooksInspection */
    protected RequestHandlerInterface $finalHandler;

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
                array_push($this->middlewares, ...array_values($middleware));
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

        array_splice($this->middlewares, $insertAt, 0, $middlewares);
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
            array_unshift($this->middlewares, ...$middlewares);

            return;
        }

        $count = count($this->middlewares);
        $tailStart = min($this->tailStart, $count);

        if ($before === '') {
            array_splice($this->middlewares, $tailStart, 0, $middlewares);

            return;
        }

        $insertAt = $tailStart;

        for ($index = $tailStart; $index < $count; $index++) {
            $currentMiddleware = $this->middlewares[$index];

            if ((is_string($currentMiddleware) ? $currentMiddleware : $currentMiddleware::class) === $before) {
                $insertAt = $index;
                break;
            }
        }

        array_splice($this->middlewares, $insertAt, 0, $middlewares);
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

        for ($index = $count - 1; $index >= $tailStart; $index--) {
            $currentMiddleware = $this->middlewares[$index];

            if ((is_string($currentMiddleware) ? $currentMiddleware : $currentMiddleware::class) !== $middlewareClass) {
                continue;
            }

            array_splice($this->middlewares, $index, 1);
            $removed++;
        }

        return $removed;
    }

    /**
     * Replaces the configured final handler or the current request final handler.
     */
    final public function setFinalHandler(RequestHandlerInterface $handler): void
    {
        $this->finalHandler = $handler;
    }
}
