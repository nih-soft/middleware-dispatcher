# Dispatch-Time Control

This document covers the per-request dispatch-time behavior available during `handle()`.

For installation, base pipeline configuration, and bootstrap examples, see [README](../README.md).

## Contents

- [Mental Model](#mental-model)
- [How `DispatchControl` Gets Into The Request](#how-dispatchcontrol-gets-into-the-request)
- [Dispatch-Time Control Example](#dispatch-time-control-example)
- [Dispatch-Time Mutation Semantics](#dispatch-time-mutation-semantics)
- [Addressing Rules](#addressing-rules)
- [`DispatchControl` API](#dispatchcontrol-api)
- [`bypassOuter()` vs Short-Circuit](#bypassouter-vs-short-circuit)
- [Behavioral Notes](#behavioral-notes)
- [Common Pitfalls](#common-pitfalls)

## Mental Model

- before `handle()`, `MiddlewareDispatcher` is the configuration object for the pipeline;
- during `handle()`, `DispatchControl` is the per-request dispatch-time control object;
- both expose similar mutation methods, but they operate on different state.

## How `DispatchControl` Gets Into The Request

During `handle()`, the dispatcher creates a dispatch-time control object and may store it in the request attributes.

```php
new MiddlewareDispatcher(
    ContainerInterface $container,
    array $middlewares,
    RequestHandlerInterface|string $finalHandler,
    string $attributeName = DispatchControl::class,
)
```

- by default, the control object is written to the request attribute named `DispatchControl::class`;
- if you pass a custom `$attributeName`, the control object is written there instead;
- if `$attributeName` is an empty string, the control object is not written to the request;
- if the request already contains that attribute, the dispatcher leaves it untouched.

If middleware expects `$request->getAttribute(DispatchControl::class)`, that only works when the default attribute name is used and not already occupied by something else.

For the simplest setup:

- keep the default attribute name `DispatchControl::class`;
- read the control object via `$request->getAttribute(DispatchControl::class)`.

If you use custom attribute names or nested dispatchers, treat that as an application-level contract and document which control object middleware is expected to read. For parent/child coordination, see [nested-dispatchers.md](nested-dispatchers.md).

## Dispatch-Time Control Example

This example assumes the default request attribute name `DispatchControl::class`.

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

        // Route-aware logic may add extra middleware for the current request.
        $control->append(App\Http\Middleware\Audit::class);

        // A current request may remove middleware that is still ahead in the tail.
        $control->remove(App\Http\Middleware\LegacyPolicy::class);

        // This request may finish with a different fallback handler.
        $control->setFinalHandler(App\Tenant\Http\Handler\TenantFallbackHandler::class);

        return $handler->handle($request);
    }
}
```

## Dispatch-Time Mutation Semantics

### Before `handle()`

When you call mutation methods on `MiddlewareDispatcher` before starting the pipeline:

- `append()`, `prepend()`, and `remove()` change the configured middleware list;
- `setFinalHandler()` changes the configured final handler and accepts either a direct handler instance or a class string resolved lazily;
- the changes affect subsequent requests handled by that dispatcher instance.

### During `handle()`

When you call the same methods on `DispatchControl` from middleware:

- they mutate only the current request's dispatch-time middleware tail;
- they never mutate middleware that has already executed;
- `setFinalHandler()` replaces the final handler only for the current request and accepts either a direct handler instance or a class string resolved lazily;
- changes do not leak into the next request.

This is useful when middleware decides that the current request should finish
with a different final handler than the one configured earlier.

If the remaining tail is already exhausted, adding or removing middleware is too late for the current request. In particular, if code in the final handler appends or prepends middleware, or replaces the final handler again, those changes do not affect that request and do not leak into the next one.

### What "remaining tail" means

In simple terms: once middleware is already running, it can change only what will
run after it, not what has already run before it.

If the current dispatch-time pipeline is:

```php
[A, B, C]
```

and execution is currently inside `B`, then dispatch-time mutation may affect only `C` and what comes after it.

For example:

- `append(X)` produces `[A, B, C, X]`
- `prepend(X)` produces `[A, B, X, C]`
- `remove(C::class)` produces `[A, B]`

## Addressing Rules

Mutation methods address middleware by FQCN:

- `remove()` removes all matches of the given class from the remaining tail;
- `append(..., $after)` searches from the end of the remaining tail and inserts after the first matching class it finds;
- `prepend(..., $before)` searches from the start of the remaining tail and inserts before the first matching class it finds.

If no matching anchor is found:

- `append(..., $after)` inserts at the end of the remaining tail;
- `prepend(..., $before)` inserts at the start of the remaining tail.

When a middleware entry is an object instance, its class is matched via `$middleware::class`.

## `DispatchControl` API

Available methods:

- `append(MiddlewareInterface|string|array $middleware, string $after = ''): void`
  Adds middleware to the remaining tail of the current request.
- `prepend(MiddlewareInterface|string|array $middleware, string $before = ''): void`
  Inserts middleware earlier in the remaining tail of the current request.
- `remove(string $middlewareClass): int`
  Removes all matching middleware entries from the remaining tail of the current request.
- `setFinalHandler(RequestHandlerInterface|string $handler): void`
  Replaces the current request final handler. The final handler is not a middleware entry, so it is managed separately from `append()`, `prepend()`, and `remove()`.
- `bypassOuter(): void`
  Marks the current middleware as the unwind boundary. See [`bypassOuter()` vs Short-Circuit](#bypassouter-vs-short-circuit).

Accepted middleware values for `append()` and `prepend()`:

- a single `MiddlewareInterface` instance;
- a middleware class string;
- a list of such entries.

## `bypassOuter()` vs Short-Circuit

### Normal short-circuit

In normal PSR-15 short-circuit behavior:

- a middleware returns a `ResponseInterface` without calling `$handler->handle()`;
- outer middleware that already entered the nested call chain still continue normal unwind and receive that response.

### `bypassOuter()`

In simple terms: `bypassOuter()` does not stop inner execution immediately.
It sets a "stop unwinding here" marker: the inner pipeline may continue, but
once the response comes back to that middleware, outer middleware above it are skipped.

`bypassOuter()` is different:

- it marks the current middleware `Fiber` as the boundary for the response return path;
- the call itself does not stop execution immediately;
- the current middleware may continue running and may still call the next handler;
- when that marked middleware later returns a response, the dispatcher returns that response directly without resuming outer middleware above that boundary;
- if a deeper middleware calls `bypassOuter()` later, the deeper call replaces the previous boundary.

`bypassOuter()` must be called directly from the dispatcher-managed middleware fiber. Calls from the final handler or from nested/user-created fibers are invalid.

### Trace Example

If `B` calls `bypassOuter()` in this pipeline:

```text
A: enter
B: enter
B: bypassOuter()
C: enter
handler
C: exit
B: exit
-- dispatcher returns the response here --
A: exit is skipped
```

The important point is that `bypassOuter()` marks the unwind boundary. It does not end execution immediately.

`bypassOuter()` also does not swallow exceptions. If an exception escapes later, it still propagates normally.

## Behavioral Notes

- middleware class strings are resolved lazily when execution reaches them;
- removed or unreached class-string middleware are never resolved;
- reentrant `handle()` calls on the same dispatcher instance are not supported and raise a `RuntimeException`.

## Common Pitfalls

- reading `DispatchControl` from the wrong request attribute name;
- expecting dispatch-time mutation to change the next request;
- expecting `bypassOuter()` to stop execution immediately;
- expecting middleware added from the final handler to still run for the current request;
- calling `handle()` reentrantly on the same dispatcher instance.
