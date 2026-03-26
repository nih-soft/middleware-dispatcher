<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Unit;

use NIH\MiddlewareDispatcher\MiddlewareDispatcher;
use NIH\MiddlewareDispatcher\Tests\Fixtures\Controllers\Dispatch\DispatchTrace;
use NIH\MiddlewareDispatcher\Tests\Fixtures\Container\TestContainer;
use NIH\MiddlewareDispatcher\Tests\Fixtures\Http\FakeResponseFactory;
use NIH\MiddlewareDispatcher\Tests\Fixtures\Http\FakeServerRequest;
use NIH\MiddlewareDispatcher\Tests\Fixtures\Middleware\RecordRouteMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ManuallyResolvedDispatcherMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        throw new \LogicException('Not needed in this test.');
    }
}

final class MiddlewareDispatcherTest extends TestCase
{
    public function test_it_can_resolve_middleware_manually(): void
    {
        $resolvedMiddleware = new ManuallyResolvedDispatcherMiddleware();
        $container = new TestContainer([
            ManuallyResolvedDispatcherMiddleware::class => $resolvedMiddleware,
        ]);
        $dispatcher = new MiddlewareDispatcher(
            $container,
            [],
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \LogicException('Final handler must not be called.');
                }
            },
        );

        self::assertSame($resolvedMiddleware, $dispatcher->resolveMiddleware(ManuallyResolvedDispatcherMiddleware::class));
        self::assertSame($resolvedMiddleware, $dispatcher->resolveMiddleware($resolvedMiddleware));
    }

    public function test_it_resolves_middleware_list_lazily_on_handle(): void
    {
        $container = new TestContainer();
        $dispatcher = new MiddlewareDispatcher(
            $container,
            [\stdClass::class],
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \LogicException('Final handler must not be called.');
                }
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Resolved middleware stdClass must implement');

        $dispatcher->handle(new FakeServerRequest('/lazy', 'GET'));
    }

    public function test_it_does_not_resolve_unreached_middleware_after_short_circuit(): void
    {
        $responseFactory = new FakeResponseFactory();
        $container = new TestContainer();
        $dispatcher = new MiddlewareDispatcher(
            $container,
            [
                new class($responseFactory) implements MiddlewareInterface {
                    public function __construct(
                        private FakeResponseFactory $responseFactory,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        return $this->responseFactory
                            ->createResponse(204)
                            ->withHeader('X-Short-Circuit', 'yes');
                    }
                },
                \stdClass::class,
            ],
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \LogicException('Final handler must not be called.');
                }
            },
        );

        $response = $dispatcher->handle(new FakeServerRequest('/lazy-short-circuit', 'GET'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Short-Circuit'));
    }

    public function test_it_resolves_instance_class_string_and_direct_middleware_objects(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();

        $resolvedMiddleware = new class($trace) implements MiddlewareInterface {
            public function __construct(
                private DispatchTrace $trace,
            ) {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->trace->add('resolved:enter');
                $response = $handler->handle($request->withAttribute(
                    'routePipeline',
                    $this->appendLabel($request, 'resolved'),
                ));
                $this->trace->add('resolved:exit');

                return $response->withAddedHeader('X-Route-Middleware', 'resolved');
            }

            private function appendLabel(ServerRequestInterface $request, string $label): string
            {
                $current = $request->getAttribute('routePipeline');

                return is_string($current) && $current !== ''
                    ? $current . '>' . $label
                    : $label;
            }
        };
        $resolvedClass = $resolvedMiddleware::class;

        $container = new TestContainer([
            $resolvedClass => $resolvedMiddleware,
        ]);

        $dispatcher = new MiddlewareDispatcher(
            $container,
            [
                new class($trace) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $this->trace->add('instance:enter');
                        $current = $request->getAttribute('routePipeline');
                        $current = is_string($current) && $current !== ''
                            ? $current . '>instance'
                            : 'instance';
                        $response = $handler->handle($request->withAttribute('routePipeline', $current));
                        $this->trace->add('instance:exit');

                        return $response->withAddedHeader('X-Route-Middleware', 'instance');
                    }
                },
                $resolvedClass,
                new RecordRouteMiddleware($responseFactory, $trace, 'direct'),
            ],
            new class($trace, $responseFactory) implements RequestHandlerInterface {
                public function __construct(
                    private DispatchTrace $trace,
                    private FakeResponseFactory $responseFactory,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->trace->add('handler');

                    return $this->responseFactory
                        ->createResponse(200)
                        ->withHeader('X-Route-Pipeline', (string) ($request->getAttribute('routePipeline') ?? ''));
                }
            },
        );

        $response = $dispatcher->handle(new FakeServerRequest('/fiber', 'GET'));

        self::assertSame([
            'instance:enter',
            'resolved:enter',
            'direct:enter',
            'handler',
            'direct:exit',
            'resolved:exit',
            'instance:exit',
        ], $trace->all());
        self::assertSame('instance>resolved>direct', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame(['direct', 'resolved', 'instance'], $response->getHeader('X-Route-Middleware'));
    }
}
