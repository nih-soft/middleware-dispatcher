# NIH Middleware Dispatcher

Fiber-based PSR-15 middleware dispatcher for large or dynamic middleware pipelines.

## Why Another PSR-15 Dispatcher?

This package is not trying to replace every PSR-15 dispatcher.

Classic PSR-15 dispatchers work well for ordinary static middleware stacks. The problem appears when:

- stack traces grow with middleware depth;
- middleware needs to influence what happens later in the same request.

This package exists to solve exactly those two problems.

It executes the pipeline through `Fiber` and a loop, so stack growth stays under control. It also allows middleware to mutate the remaining pipeline and replace the current request final handler at runtime.

If those problems do not apply to your application, a normal PSR-15 dispatcher is probably enough.

## When To Use It

Use this package when your PSR-15 pipeline is not fully static and middleware needs to influence what happens later in the same request.

Typical cases:

- large middleware pipelines where stack trace size becomes an operational problem;
- route-aware, tenant-aware, or module-aware middleware stacks assembled incrementally;
- feature flags that inject or remove later middleware;
- request-scoped fallback handlers;
- advanced unwind behavior where outer middleware should be skipped.

---

## Contents

- [Installation](#installation)
- [Quick Start](#quick-start)
- [Configuration Before `handle()`](#configuration-before-handle)
- [Before and During `handle()`](#before-and-during-handle)
- [Configuration API](#configuration-api)
- [Dispatch-Time Control](#dispatch-time-control)
- [Testing](#testing)

## Installation

```bash
composer require nih/middleware-dispatcher
```

Requires PHP `8.4` or `8.5`.

This package is intentionally framework-agnostic. It depends on PSR interfaces, not on a specific framework.

## Quick Start

Assume `$container` is your PSR-11 container and `$request` is a PSR-7 `ServerRequestInterface` created by your framework or HTTP layer.

Even a small application often has several middleware layers.

```php
<?php

use NIH\MiddlewareDispatcher\Pipeline;
use NIH\MiddlewareDispatcher\MiddlewareDispatcher;

$pipeline = new Pipeline(
    [
        App\Http\Middleware\ErrorHandlerMiddleware::class,
        App\Http\Middleware\RequestIdMiddleware::class,
        App\Http\Middleware\RoutingMiddleware::class,
        App\Http\Middleware\Authenticate::class,
        App\Http\Middleware\Authorize::class,
        new App\Http\Middleware\AuditMiddleware(),
        App\Http\Middleware\LocaleMiddleware::class,
    ],
    App\Http\Handler\NotFoundHandler::class,
);

$dispatcher = new MiddlewareDispatcher(
    $container,
    $pipeline,
);

$response = $dispatcher->handle($request);
```

Middleware entries may be:

- direct `MiddlewareInterface` instances;
- middleware class strings resolved lazily from the provided `ContainerInterface`.

If you use a class string, the container must resolve it to an object that implements `MiddlewareInterface`.

For larger applications, see [Configuration Before `handle()`](#configuration-before-handle).

## Configuration Before `handle()`

A larger application often uses two setup steps before the first request is handled:

- configuration phase: create the base pipeline and default final handler on `Pipeline`;
- dispatch phase: create `MiddlewareDispatcher` from that pipeline and call `handle()`.

The configuration phase may happen in bootstrap code, another class, or a different module.
The same applies to the final handler: the pipeline constructor may provide the default final handler first, and later configuration may replace it through `setFinalHandler()`.

```php
<?php

use NIH\MiddlewareDispatcher\Pipeline;
use NIH\MiddlewareDispatcher\MiddlewareDispatcher;

$pipeline = new Pipeline(
    [
        App\Http\Middleware\ErrorHandlerMiddleware::class,
        App\Http\Middleware\RequestIdMiddleware::class,
        App\Http\Middleware\RoutingMiddleware::class,
        App\Http\Middleware\Authenticate::class,
    ],
    App\Http\Handler\NotFoundHandler::class,
);

// This may be called from a different file, class, or module during bootstrap.
$pipeline->append(
    [
        App\Admin\Http\Middleware\LoadAdminContext::class,
        App\Admin\Http\Middleware\RequireAdminRole::class,
    ],
    after: App\Http\Middleware\Authenticate::class,
);

// This may also be called from another file, class, or module before handle().
$pipeline->prepend(
    [
        App\Tenant\Http\Middleware\DetectTenantFromHost::class,
        App\Tenant\Http\Middleware\SwitchTenantConnection::class,
    ],
    before: App\Http\Middleware\RoutingMiddleware::class,
);

// A feature flag or environment-specific bootstrap may remove middleware configured earlier.
$pipeline->remove(App\Http\Middleware\DebugToolbarMiddleware::class);

// The final handler is configured separately because it is not middleware.
$pipeline->setFinalHandler(App\Admin\Http\Handler\AdminFallbackHandler::class);

$dispatcher = new MiddlewareDispatcher($container, $pipeline);

$response = $dispatcher->handle($request);
```

Middleware class strings remain lazy in both phases and are resolved only when execution reaches them.

## Before and During `handle()`

| Question          | Before `handle()`                                               | During `handle()`                                                                |
|-------------------|-----------------------------------------------------------------|----------------------------------------------------------------------------------|
| Main object       | `Pipeline`                                                       | `PipelineControl`                                                                |
| Typical place     | bootstrap, constructor setup, module configuration              | currently running middleware                                                     |
| What can change   | configured middleware list and configured final handler         | only the remaining tail for the current request and that request's final handler |
| Scope             | affects any dispatcher using that pipeline object               | affects only the current request                                                 |
| How you access it | direct variable/reference to the pipeline                       | request attribute, if the dispatcher is configured to expose it                  |

## Configuration API

### `Pipeline`

```php
new Pipeline(
    array $middlewares = [],
    RequestHandlerInterface|string $finalHandler = '',
)
```

Available methods:

- `append(MiddlewareInterface|string|array $middleware, string $after = ''): void`
- `prepend(MiddlewareInterface|string|array $middleware, string $before = ''): void`
- `remove(string $middlewareClass): int`
- `setFinalHandler(RequestHandlerInterface|string $handler): void`

Before `handle()` starts, `Pipeline` acts as the configuration object for the pipeline.

- `append(..., $after)` inserts after the last matching middleware in the configured pipeline. If no match is found, it appends to the end of the configured pipeline.
- `prepend(..., $before)` inserts before the first matching middleware in the configured pipeline. If no match is found, it prepends to the start of the configured pipeline.
- `setFinalHandler()` replaces the configured final handler. The final handler is the `RequestHandlerInterface` that runs when the middleware pipeline is exhausted. It may be provided either as a direct handler instance or as a class string resolved lazily through the configured container. The constructor may also omit it temporarily by leaving the default empty string and setting it later before `handle()` starts. It is not a middleware entry, so it is managed separately from `append()`, `prepend()`, and `remove()`.

### `MiddlewareDispatcher`

```php
new MiddlewareDispatcher(
    ContainerInterface $container,
    Pipeline $pipeline,
    string $attributeName = PipelineControl::class,
)
```

Available methods:

- `handle(ServerRequestInterface $request): ResponseInterface`

`MiddlewareDispatcher` executes a previously prepared `Pipeline`. It uses the original pipeline object passed to the constructor, so later mutations of that pipeline object affect subsequent `handle()` calls on that dispatcher. The optional `$attributeName` constructor argument controls how dispatch-time `PipelineControl` is exposed during `handle()`. See [Dispatch-Time Control](#dispatch-time-control).

## Dispatch-Time Control

During `handle()`, the dispatcher may expose a per-request `PipelineControl` object through request attributes. Its API intentionally mirrors the pre-dispatch configuration phase: the same `append()`, `prepend()`, `remove()`, and `setFinalHandler()` methods are available after the pipeline has started, plus the dispatch-time-specific `bypassOuter()`. That keeps dynamic dispatch behavior predictable and reduces cognitive overhead for developers.

At dispatch time, `setFinalHandler()` has the same conceptual role, but it affects only the current request. Middleware may use it to replace the currently configured final handler for that request, either with a direct handler instance or with a lazily resolved class string.

For dispatch-time mutation semantics, request attribute behavior, and `bypassOuter()` details, see [docs/dispatch-time-control.md](docs/dispatch-time-control.md). For advanced parent/child dispatcher coordination, see [docs/nested-dispatchers.md](docs/nested-dispatchers.md).

## Testing

```bash
composer test
```
