# NIH Middleware Dispatcher

Fiber-based PSR-15 middleware dispatcher with lazy middleware resolution through a PSR-11 container.

## Installation

```bash
composer require nih/middleware-dispatcher
```

## Usage

```php
<?php

use NIH\MiddlewareDispatcher\MiddlewareDispatcher;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$dispatcher = new MiddlewareDispatcher(
    $container,
    [
        App\Http\Middleware\Authenticate::class,
        new App\Http\Middleware\RecordTrace(),
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

Middleware entries may be direct `MiddlewareInterface` instances or class strings resolved lazily from the provided `ContainerInterface`.

## Testing

```bash
composer test
```
