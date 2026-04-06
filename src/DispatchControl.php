<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher;

use Fiber;
use RuntimeException;

/**
 * Per-request runtime control object exposed through the request attributes.
 *
 * Mutation methods change only the remaining tail of the current request and do
 * not affect the next request handled by the dispatcher instance.
 */
final class DispatchControl extends DispatcherData
{
    protected function __construct(MiddlewareDispatcher $dispatcher)
    {
        $this->middlewares = $dispatcher->middlewares;
        $this->finalHandler = $dispatcher->finalHandler;
    }

    /**
     * Marks the current middleware as the boundary for the response return path.
     *
     * The call does not stop execution immediately. When the marked middleware later
     * returns a response, outer middleware above that boundary are not resumed.
     * A deeper later call replaces the previously marked boundary.
     *
     * @throws RuntimeException If called outside the dispatcher-managed middleware fiber.
     */
    public function bypassOuter(): void
    {
        $fiber = Fiber::getCurrent();

        if (!$fiber instanceof Fiber || $fiber !== $this->activeMiddlewareFiber) {
            throw new RuntimeException('bypassOuter() can only be called from middleware fiber.');
        }

        $this->bypassOuterFiber = $fiber;
    }
}
