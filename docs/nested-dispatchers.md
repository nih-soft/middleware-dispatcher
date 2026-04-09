# Nested And Child Dispatchers

This document covers advanced coordination between parent and child dispatcher instances.

> Advanced topic:
> Most applications do not need nested dispatchers at all.
> This document matters only when one middleware pipeline explicitly delegates part of the work to another dispatcher and both participate in the same request.

For the ordinary dispatch-time API, request-attribute exposure, and `bypassOuter()`, see [dispatch-time-control.md](dispatch-time-control.md).

## Isolation Model

Child dispatchers remain isolated from the parent dispatcher instance.

That isolation applies to dispatcher state itself:

- a child dispatcher does not mutate the parent dispatcher instance directly;
- a parent dispatcher does not automatically share its mutable runtime state with the child;
- each dispatcher still manages its own configured middleware list, current runtime tail, and current final handler.

## `DispatchRuntime` Through Request Attributes

Isolation does not prevent intentional coordination through request attributes.

For example:

- a parent dispatcher may expose its `DispatchRuntime` under one attribute name;
- a child dispatcher may expose its own `DispatchRuntime` under another attribute name;
- middleware running inside the child dispatcher may still read and use the parent control object from the request.

That is explicit coordination through the request, not shared dispatcher state.

## Example Shape

```php
<?php

$parentConfig = new DispatchConfig(
    $parentMiddlewares,
    $parentFinalHandler,
);

$parentDispatcher = new MiddlewareDispatcher(
    $container,
    $parentConfig,
    'parentDispatchControl',
);

$childConfig = new DispatchConfig(
    $childMiddlewares,
    $childFinalHandler,
);

$childDispatcher = new MiddlewareDispatcher(
    $container,
    $childConfig,
    'childDispatchControl',
);
```

Inside child middleware:

```php
<?php

$parentControl = $request->getAttribute('parentDispatchControl');
$childControl = $request->getAttribute('childDispatchControl');
```

From there, the application may decide which control object is allowed to mutate which part of the flow.

## Recommended Conventions

If your application uses nested dispatchers:

- use explicit attribute names instead of the default for all participating dispatchers;
- document which middleware reads which control object;
- keep parent/child coordination rare and intentional;
- treat request-attribute access as part of your application contract.

## Risks

Common sources of confusion:

- reading the wrong control object;
- assuming the default attribute name is always safe;
- creating hidden coupling between otherwise independent middleware stacks;
- expecting nested dispatchers to coordinate automatically.
