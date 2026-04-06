# NIH Middleware Dispatcher

Fiber-based PSR-15 middleware dispatcher with lazy middleware resolution through a PSR-11 container.

This package exists because classic PSR-15 dispatchers usually execute middleware as a deep chain of nested method calls.

When the pipeline becomes large, that creates a real cost:

- exceptions produce very large stack traces;
- the execution stack grows linearly with the middleware count.

In practice, that means harder debugging, noisier production errors, and extra overhead in edge cases where exceptions are frequent or code depends on stack inspection, logging, formatting, or tracing.

This dispatcher executes the pipeline through `Fiber` and a loop, so the execution stack does not grow linearly with the middleware count.

It also adds runtime flow control that classic nested PSR-15 dispatchers do not provide cleanly: middleware can mutate the remaining tail of the current pipeline, replace the final handler for the current request, and optionally bypass outer middleware during unwind.

Looking for the API reference first? See [Contents](#contents).

## Why Another PSR-15 Dispatcher?

Most PSR-15 dispatchers are intentionally simple: one middleware calls the next one, which calls the next one again, and so on.

That model is fine for ordinary static middleware stacks. The problem appears when:

- the pipeline is large enough that stack trace size becomes painful;
- middleware needs to affect what happens later in the same request.

This package exists to solve both problems.

### Why the stack size matters

With a classic nested dispatcher, an exception raised deep in the pipeline produces a very large stack trace.

That means:

- harder debugging;
- more noise in production error reports;
- extra overhead when exceptions are logged, formatted, inspected, or traced;
- slower behavior in exception-heavy paths and in code paths that depend on stack introspection.

### Why runtime control matters

Classic nested PSR-15 dispatchers do not provide this kind of runtime flow control cleanly, because outer middleware are already waiting inside nested `$handler->handle()` calls.

This dispatcher allows middleware to:

- append or prepend middleware to the remaining tail of the current request;
- remove middleware from the remaining tail;
- replace the current request's final handler;
- bypass outer middleware on unwind via `bypassOuter()`.

### Why lazy resolution matters

Middleware class strings are resolved only when execution actually reaches them.

That keeps middleware loading lazy by default and allows earlier middleware to affect runtime DI/container configuration before later middleware are resolved.

## When To Use It

Use this package if your middleware pipeline is not fully static and you need middleware to influence what happens later in the same request.

Typical cases:

- large middleware pipelines where stack trace size is a real operational problem;
- route-aware pipelines assembled incrementally;
- feature flags that inject or remove later middleware;
- request-scoped fallback handlers;
- advanced unwind behavior where outer middleware should be skipped.

## When You Probably Do Not Need It

If your middleware stack is static and a normal PSR-15 dispatcher already does the job, this package is probably unnecessary.

The main value here is not just "PSR-15 support". The main value is:

- controlled stack growth;
- runtime mutation of the remaining pipeline.

---

## Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Public API](#public-api)
- [Runtime Control Example](#runtime-control-example)
- [Mutation Semantics](#mutation-semantics)
- [`bypassOuter()` vs Short-Circuit](#bypassouter-vs-short-circuit)
- [Behavioral Notes](#behavioral-notes)
- [Testing](#testing)

## Installation

```bash
composer require nih/middleware-dispatcher
```

Requires PHP `8.4` or `8.5`.

This package is intentionally framework-agnostic. It depends on PSR interfaces, not on a specific framework.

## Quick Start

Assume `$container` is your PSR-11 container and `$request` is a PSR-7 `ServerRequestInterface` created by your framework or HTTP layer.

```php
<?php

use NIH\MiddlewareDispatcher\MiddlewareDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$dispatcher = new MiddlewareDispatcher(
    $container,
    [
        App\Http\Middleware\Authenticate::class,
        new App\Http\Middleware\AuditMiddleware(),
    ],
    new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new App\Http\Response\OkResponse();
        }
    },
);

$response = $dispatcher->handle($request);
```

Middleware entries may be:

- direct `MiddlewareInterface` instances;
- middleware class strings resolved lazily from the provided `ContainerInterface`.

## Public API

### `MiddlewareDispatcher`

```php
new MiddlewareDispatcher(
    ContainerInterface $container,
    array $middlewares,
    RequestHandlerInterface $finalHandler,
    string $attributeName = DispatchControl::class,
)
```

Available methods:

- `handle(ServerRequestInterface $request): ResponseInterface`
- `append(MiddlewareInterface|string|array $middleware, string $after = ''): void`
- `prepend(MiddlewareInterface|string|array $middleware, string $before = ''): void`
- `remove(string $middlewareClass): int`
- `setFinalHandler(RequestHandlerInterface $handler): void`
- `resolveMiddleware(MiddlewareInterface|string $middleware): MiddlewareInterface`

Before `handle()` starts, `MiddlewareDispatcher` acts as the configuration object for the pipeline.

### `DispatchControl`

During `handle()`, the dispatcher creates a runtime control object and stores it in the request attribute named by `$attributeName`, if that attribute is not already present.

Available methods:

- `append(MiddlewareInterface|string|array $middleware, string $after = ''): void`
- `prepend(MiddlewareInterface|string|array $middleware, string $before = ''): void`
- `remove(string $middlewareClass): int`
- `setFinalHandler(RequestHandlerInterface $handler): void`
- `bypassOuter(): void`

If `$attributeName` is an empty string, the control object is not written to the request.

If the attribute already exists, the dispatcher leaves it untouched.

## Runtime Control Example

```php
<?php

use NIH\MiddlewareDispatcher\DispatchControl;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class RuntimeMutationMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $control = $request->getAttribute(DispatchControl::class);

        if (!$control instanceof DispatchControl) {
            throw new \LogicException('Dispatch control attribute is missing.');
        }

        $control->append(App\Http\Middleware\Audit::class);
        $control->remove(App\Http\Middleware\LegacyPolicy::class);
        $control->setFinalHandler(new App\Http\Handler\FallbackHandler());

        return $handler->handle($request);
    }
}
```

## Mutation Semantics

### Before `handle()`

When you call mutation methods on `MiddlewareDispatcher` before starting the pipeline:

- `append()`, `prepend()`, and `remove()` change the configured middleware list;
- `setFinalHandler()` changes the configured final handler;
- the changes affect subsequent requests handled by that dispatcher instance.

### During `handle()`

When you call the same methods on `DispatchControl` from middleware:

- they mutate only the current request's runtime middleware tail;
- they never mutate middleware that has already executed;
- `setFinalHandler()` replaces the final handler only for the current request;
- changes do not leak into the next request.

### What "remaining tail" means

If the current runtime pipeline is:

```php
[A, B, C]
```

and execution is currently inside `B`, then runtime mutation may affect only `C` and what comes after it.

For example:

- `append(X)` produces `[A, B, C, X]`
- `prepend(X)` produces `[A, B, X, C]`
- `remove(C::class)` produces `[A, B]`

### Middleware addressing rules

Mutation methods address middleware by FQCN:

- `remove()` removes all matches of the given class from the remaining tail;
- `append(..., $after)` searches from the end of the remaining tail and inserts after the first matching class it finds;
- `prepend(..., $before)` searches from the start of the remaining tail and inserts before the first matching class it finds.

When a middleware entry is an object instance, its class is matched via `$middleware::class`.

### Accepted middleware values

`append()` and `prepend()` accept either:

- a single `MiddlewareInterface` instance;
- a middleware class string;
- a list of such entries.

## `bypassOuter()` vs Short-Circuit

### Normal short-circuit

In normal PSR-15 short-circuit behavior:

- a middleware returns a `ResponseInterface` without calling `$handler->handle()`;
- outer middleware that already entered the nested call chain still continue normal unwind and receive that response.

### `bypassOuter()`

`bypassOuter()` is different:

- it marks the current middleware `Fiber` as the boundary for the response return path;
- the call itself does not stop execution immediately;
- the current middleware may continue running and may still call the next handler;
- when that marked middleware later returns a response, the dispatcher returns that response directly without resuming outer middleware above that boundary;
- if a deeper middleware calls `bypassOuter()` later, the deeper call replaces the previous boundary.

`bypassOuter()` must be called directly from the dispatcher-managed middleware fiber. Calls from the final handler or from nested/user-created fibers are invalid.

Use it when a middleware must decide that outer layers should not run after the inner response is produced.

## Behavioral Notes

- middleware class strings are resolved lazily when execution reaches them;
- removed or unreached class-string middleware are never resolved;
- reentrant `handle()` calls on the same dispatcher instance are not supported and raise a `RuntimeException`;
- `bypassOuter()` may only be called from a middleware fiber;
- calling `bypassOuter()` does not swallow exceptions;
- if the request already contains the control attribute name, the dispatcher does not overwrite it;
- if you want middleware to read `DispatchControl` from the request, make sure that attribute name is available and not already occupied by something else;
- child dispatchers remain isolated from the parent dispatcher instance.

## Testing

```bash
composer test
```
