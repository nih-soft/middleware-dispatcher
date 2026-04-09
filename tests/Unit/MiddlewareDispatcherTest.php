<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Unit;

use NIH\MiddlewareDispatcher\DispatchRuntime;
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

final readonly class MutableRouteMiddleware implements MiddlewareInterface
{
    public function __construct(
        private FakeResponseFactory $responseFactory,
        private DispatchTrace $trace,
        private string $label,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $this->trace->add($this->label . ':enter');
        $current = $request->getAttribute('routePipeline');
        $current = is_string($current) && $current !== ''
            ? $current . '>' . $this->label
            : $this->label;
        $response = $handler->handle($request->withAttribute('routePipeline', $current));
        $this->trace->add($this->label . ':exit');

        return $response->withAddedHeader('X-Route-Middleware', $this->label);
    }
}

final class AutoResolvedLazyRuntimeMiddleware implements MiddlewareInterface
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $current = $request->getAttribute('routePipeline');
        $current = is_string($current) && $current !== ''
            ? $current . '>lazy-runtime'
            : 'lazy-runtime';
        $response = $handler->handle($request->withAttribute('routePipeline', $current));

        return $response->withAddedHeader('X-Route-Middleware', 'lazy-runtime');
    }
}

final class InvalidResolvedLazyMiddleware
{
}

final class AutoResolvedConfiguredFinalHandler implements RequestHandlerInterface
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new FakeResponseFactory())
            ->createResponse(200)
            ->withHeader('X-Final-Handler', 'configured');
    }
}

final class AutoResolvedReplacementFinalHandler implements RequestHandlerInterface
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new FakeResponseFactory())
            ->createResponse(200)
            ->withHeader('X-Final-Handler', 'replacement');
    }
}

final class AutoResolvedRuntimeFinalHandler implements RequestHandlerInterface
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return (new FakeResponseFactory())
            ->createResponse(200)
            ->withHeader('X-Final-Handler', 'runtime');
    }
}

final class MiddlewareDispatcherTest extends TestCase
{
    public function test_it_can_append_middleware_before_pipeline_start(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new RecordRouteMiddleware($responseFactory, $trace, 'base'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $dispatcher->append(new RecordRouteMiddleware($responseFactory, $trace, 'appended'));

        $response = $dispatcher->handle(new FakeServerRequest('/append-before-start', 'GET'));

        self::assertSame('base>appended', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'base:enter',
            'appended:enter',
            'handler',
            'appended:exit',
            'base:exit',
        ], $trace->all());
    }

    public function test_it_can_prepend_middleware_before_pipeline_start(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new RecordRouteMiddleware($responseFactory, $trace, 'base'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $dispatcher->prepend(new RecordRouteMiddleware($responseFactory, $trace, 'prepended'));

        $response = $dispatcher->handle(new FakeServerRequest('/prepend-before-start', 'GET'));

        self::assertSame('prepended>base', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'prepended:enter',
            'base:enter',
            'handler',
            'base:exit',
            'prepended:exit',
        ], $trace->all());
    }

    public function test_it_can_append_a_middleware_chain_before_pipeline_start(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new RecordRouteMiddleware($responseFactory, $trace, 'base'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $dispatcher->append([
            new RecordRouteMiddleware($responseFactory, $trace, 'first'),
            new RecordRouteMiddleware($responseFactory, $trace, 'second'),
        ]);

        $response = $dispatcher->handle(new FakeServerRequest('/append-chain-before-start', 'GET'));

        self::assertSame('base>first>second', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'base:enter',
            'first:enter',
            'second:enter',
            'handler',
            'second:exit',
            'first:exit',
            'base:exit',
        ], $trace->all());
    }

    public function test_it_can_prepend_a_middleware_chain_to_current_tail_during_execution(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $anchorMiddleware = new class($trace, $responseFactory) implements MiddlewareInterface {
            public function __construct(
                private DispatchTrace $trace,
                private FakeResponseFactory $responseFactory,
            ) {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->trace->add('anchor:enter');
                $current = $request->getAttribute('routePipeline');
                $current = is_string($current) && $current !== ''
                    ? $current . '>anchor'
                    : 'anchor';
                $response = $handler->handle($request->withAttribute('routePipeline', $current));
                $this->trace->add('anchor:exit');

                return $response->withAddedHeader('X-Route-Middleware', 'anchor');
            }
        };

        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class($trace, $responseFactory, $anchorMiddleware::class) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                        private FakeResponseFactory $responseFactory,
                        private string $before,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->prepend([
                            new RecordRouteMiddleware($this->responseFactory, $this->trace, 'first'),
                            new RecordRouteMiddleware($this->responseFactory, $this->trace, 'second'),
                        ], $this->before);

                        return $handler->handle($request);
                    }
                },
                $anchorMiddleware,
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $response = $dispatcher->handle(new FakeServerRequest('/prepend-chain-during-execution', 'GET'));

        self::assertSame('first>second>anchor>tail', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'first:enter',
            'second:enter',
            'anchor:enter',
            'tail:enter',
            'handler',
            'tail:exit',
            'anchor:exit',
            'second:exit',
            'first:exit',
        ], $trace->all());
    }

    public function test_it_can_append_middleware_to_current_tail_during_execution(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $anchorMiddleware = new class($trace, $responseFactory) implements MiddlewareInterface {
            public function __construct(
                private DispatchTrace $trace,
                private FakeResponseFactory $responseFactory,
            ) {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->trace->add('anchor:enter');
                $current = $request->getAttribute('routePipeline');
                $current = is_string($current) && $current !== ''
                    ? $current . '>anchor'
                    : 'anchor';
                $response = $handler->handle($request->withAttribute('routePipeline', $current));
                $this->trace->add('anchor:exit');

                return $response->withAddedHeader('X-Route-Middleware', 'anchor');
            }
        };

        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class($trace, $responseFactory, $anchorMiddleware::class) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                        private FakeResponseFactory $responseFactory,
                        private string $after,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->append(
                            new RecordRouteMiddleware($this->responseFactory, $this->trace, 'appended'),
                            $this->after,
                        );

                        return $handler->handle($request);
                    }
                },
                $anchorMiddleware,
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $response = $dispatcher->handle(new FakeServerRequest('/append-during-execution', 'GET'));

        self::assertSame('anchor>appended>tail', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'anchor:enter',
            'appended:enter',
            'tail:enter',
            'handler',
            'tail:exit',
            'appended:exit',
            'anchor:exit',
        ], $trace->all());
    }

    public function test_it_can_prepend_middleware_to_current_tail_during_execution(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $anchorMiddleware = new class($trace, $responseFactory) implements MiddlewareInterface {
            public function __construct(
                private DispatchTrace $trace,
                private FakeResponseFactory $responseFactory,
            ) {
            }

            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                $this->trace->add('anchor:enter');
                $current = $request->getAttribute('routePipeline');
                $current = is_string($current) && $current !== ''
                    ? $current . '>anchor'
                    : 'anchor';
                $response = $handler->handle($request->withAttribute('routePipeline', $current));
                $this->trace->add('anchor:exit');

                return $response->withAddedHeader('X-Route-Middleware', 'anchor');
            }
        };

        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class($trace, $responseFactory, $anchorMiddleware::class) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                        private FakeResponseFactory $responseFactory,
                        private string $before,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->prepend(
                            new RecordRouteMiddleware($this->responseFactory, $this->trace, 'prepended'),
                            $this->before,
                        );

                        return $handler->handle($request);
                    }
                },
                $anchorMiddleware,
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $response = $dispatcher->handle(new FakeServerRequest('/prepend-during-execution', 'GET'));

        self::assertSame('prepended>anchor>tail', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'prepended:enter',
            'anchor:enter',
            'tail:enter',
            'handler',
            'tail:exit',
            'anchor:exit',
            'prepended:exit',
        ], $trace->all());
    }

    public function test_it_can_append_middleware_during_unwind_for_current_request_only(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class($trace, $responseFactory) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                        private FakeResponseFactory $responseFactory,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $response = $handler->handle($request);
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->append(new RecordRouteMiddleware($this->responseFactory, $this->trace, 'late'));
                        $this->trace->add('append:unwind');

                        return $response;
                    }
                },
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $firstResponse = $dispatcher->handle(new FakeServerRequest('/append-during-unwind-first', 'GET'));
        $secondResponse = $dispatcher->handle(new FakeServerRequest('/append-during-unwind-second', 'GET'));

        self::assertSame('tail', $firstResponse->getHeaderLine('X-Route-Pipeline'));
        self::assertSame('tail', $secondResponse->getHeaderLine('X-Route-Pipeline'));
    }

    public function test_it_can_prepend_middleware_during_unwind_for_current_request_only(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class($trace, $responseFactory) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                        private FakeResponseFactory $responseFactory,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $response = $handler->handle($request);
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->prepend(new RecordRouteMiddleware($this->responseFactory, $this->trace, 'late'));
                        $this->trace->add('prepend:unwind');

                        return $response;
                    }
                },
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $firstResponse = $dispatcher->handle(new FakeServerRequest('/prepend-during-unwind-first', 'GET'));
        $secondResponse = $dispatcher->handle(new FakeServerRequest('/prepend-during-unwind-second', 'GET'));

        self::assertSame('tail', $firstResponse->getHeaderLine('X-Route-Pipeline'));
        self::assertSame('tail', $secondResponse->getHeaderLine('X-Route-Pipeline'));
    }

    public function test_it_can_remove_middleware_before_pipeline_start(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new MutableRouteMiddleware($responseFactory, $trace, 'removed-first'),
                new RecordRouteMiddleware($responseFactory, $trace, 'middle'),
                new MutableRouteMiddleware($responseFactory, $trace, 'removed-second'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $removed = $dispatcher->remove(MutableRouteMiddleware::class);
        $response = $dispatcher->handle(new FakeServerRequest('/remove-before-start', 'GET'));

        self::assertSame(2, $removed);
        self::assertSame('middle', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'middle:enter',
            'handler',
            'middle:exit',
        ], $trace->all());
    }

    public function test_it_can_remove_middleware_only_from_current_tail_during_execution(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $removed = null;
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new MutableRouteMiddleware($responseFactory, $trace, 'before'),
                new class($removed) implements MiddlewareInterface {
                    private ?int $removed;

                    public function __construct(?int &$removed)
                    {
                        $this->removed = &$removed;
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        if ($request->getQueryParams()['mutate'] ?? false) {
                            $control = $request->getAttribute(DispatchRuntime::class);

                            if (!$control instanceof DispatchRuntime) {
                                throw new \LogicException('Dispatch control attribute is missing.');
                            }

                            $this->removed = $control->remove(MutableRouteMiddleware::class);
                        }

                        return $handler->handle($request);
                    }
                },
                new MutableRouteMiddleware($responseFactory, $trace, 'after'),
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $firstResponse = $dispatcher->handle(new FakeServerRequest(
            '/remove-during-execution-first',
            'GET',
            queryParams: ['mutate' => true],
        ));
        $secondResponse = $dispatcher->handle(new FakeServerRequest('/remove-during-execution-second', 'GET'));

        self::assertSame(1, $removed);
        self::assertSame('before>tail', $firstResponse->getHeaderLine('X-Route-Pipeline'));
        self::assertSame('before>after>tail', $secondResponse->getHeaderLine('X-Route-Pipeline'));
    }

    public function test_it_can_set_final_handler_before_pipeline_start(): void
    {
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [],
            $this->createTaggedFinalHandler($responseFactory, 'base'),
        );

        $dispatcher->setFinalHandler($this->createTaggedFinalHandler($responseFactory, 'replaced'));

        $firstResponse = $dispatcher->handle(new FakeServerRequest('/set-final-before-start-first', 'GET'));
        $secondResponse = $dispatcher->handle(new FakeServerRequest('/set-final-before-start-second', 'GET'));

        self::assertSame('replaced', $firstResponse->getHeaderLine('X-Final-Handler'));
        self::assertSame('replaced', $secondResponse->getHeaderLine('X-Final-Handler'));
    }

    public function test_it_can_be_constructed_without_final_handler_until_it_is_set_before_handle(): void
    {
        AutoResolvedReplacementFinalHandler::$constructed = 0;

        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [],
        );

        $dispatcher->setFinalHandler(AutoResolvedReplacementFinalHandler::class);

        self::assertSame(0, AutoResolvedReplacementFinalHandler::$constructed);

        $response = $dispatcher->handle(new FakeServerRequest('/set-final-after-empty-constructor', 'GET'));

        self::assertSame(1, AutoResolvedReplacementFinalHandler::$constructed);
        self::assertSame('replacement', $response->getHeaderLine('X-Final-Handler'));
    }

    public function test_it_can_use_a_lazy_class_string_final_handler_from_constructor(): void
    {
        AutoResolvedConfiguredFinalHandler::$constructed = 0;

        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [],
            AutoResolvedConfiguredFinalHandler::class,
        );

        self::assertSame(0, AutoResolvedConfiguredFinalHandler::$constructed);

        $response = $dispatcher->handle(new FakeServerRequest('/set-final-from-constructor-class-string', 'GET'));

        self::assertSame(1, AutoResolvedConfiguredFinalHandler::$constructed);
        self::assertSame('configured', $response->getHeaderLine('X-Final-Handler'));
    }

    public function test_it_can_set_a_lazy_class_string_final_handler_before_pipeline_start(): void
    {
        AutoResolvedReplacementFinalHandler::$constructed = 0;

        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [],
            $this->createTaggedFinalHandler($responseFactory, 'base'),
        );

        $dispatcher->setFinalHandler(AutoResolvedReplacementFinalHandler::class);

        self::assertSame(0, AutoResolvedReplacementFinalHandler::$constructed);

        $response = $dispatcher->handle(new FakeServerRequest('/set-final-before-start-class-string', 'GET'));

        self::assertSame(1, AutoResolvedReplacementFinalHandler::$constructed);
        self::assertSame('replacement', $response->getHeaderLine('X-Final-Handler'));
    }

    public function test_it_throws_when_final_handler_is_not_configured(): void
    {
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [],
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Final handler class-string must be non-empty.');

        $dispatcher->handle(new FakeServerRequest('/missing-final-handler', 'GET'));
    }

    public function test_it_can_set_final_handler_for_current_request_only_during_execution(): void
    {
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
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
                        if ($request->getQueryParams()['mutate'] ?? false) {
                            $control = $request->getAttribute(DispatchRuntime::class);

                            if (!$control instanceof DispatchRuntime) {
                                throw new \LogicException('Dispatch control attribute is missing.');
                            }

                            $control->setFinalHandler(new class($this->responseFactory) implements RequestHandlerInterface {
                                public function __construct(
                                    private FakeResponseFactory $responseFactory,
                                ) {
                                }

                                public function handle(ServerRequestInterface $request): ResponseInterface
                                {
                                    return $this->responseFactory
                                        ->createResponse(200)
                                        ->withHeader('X-Final-Handler', 'runtime');
                                }
                            });
                        }

                        return $handler->handle($request);
                    }
                },
            ],
            $this->createTaggedFinalHandler($responseFactory, 'base'),
        );

        $firstResponse = $dispatcher->handle(new FakeServerRequest(
            '/set-final-during-execution-first',
            'GET',
            queryParams: ['mutate' => true],
        ));
        $secondResponse = $dispatcher->handle(new FakeServerRequest('/set-final-during-execution-second', 'GET'));

        self::assertSame('runtime', $firstResponse->getHeaderLine('X-Final-Handler'));
        self::assertSame('base', $secondResponse->getHeaderLine('X-Final-Handler'));
    }

    public function test_it_can_set_a_lazy_class_string_final_handler_for_current_request_only_during_execution(): void
    {
        AutoResolvedRuntimeFinalHandler::$constructed = 0;

        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class implements MiddlewareInterface {
                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        if ($request->getQueryParams()['mutate'] ?? false) {
                            $control = $request->getAttribute(DispatchRuntime::class);

                            if (!$control instanceof DispatchRuntime) {
                                throw new \LogicException('Dispatch control attribute is missing.');
                            }

                            $control->setFinalHandler(AutoResolvedRuntimeFinalHandler::class);
                        }

                        return $handler->handle($request);
                    }
                },
            ],
            $this->createTaggedFinalHandler($responseFactory, 'base'),
        );

        self::assertSame(0, AutoResolvedRuntimeFinalHandler::$constructed);

        $firstResponse = $dispatcher->handle(new FakeServerRequest(
            '/set-final-during-execution-class-string-first',
            'GET',
            queryParams: ['mutate' => true],
        ));
        $secondResponse = $dispatcher->handle(new FakeServerRequest(
            '/set-final-during-execution-class-string-second',
            'GET',
        ));

        self::assertSame(1, AutoResolvedRuntimeFinalHandler::$constructed);
        self::assertSame('runtime', $firstResponse->getHeaderLine('X-Final-Handler'));
        self::assertSame('base', $secondResponse->getHeaderLine('X-Final-Handler'));
    }

    public function test_it_lazily_resolves_runtime_appended_class_string_middleware(): void
    {
        AutoResolvedLazyRuntimeMiddleware::$constructed = 0;

        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class implements MiddlewareInterface {
                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->append(AutoResolvedLazyRuntimeMiddleware::class);

                        return $handler->handle($request);
                    }
                },
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        self::assertSame(0, AutoResolvedLazyRuntimeMiddleware::$constructed);

        $response = $dispatcher->handle(new FakeServerRequest('/lazy-runtime-append', 'GET'));

        self::assertSame(1, AutoResolvedLazyRuntimeMiddleware::$constructed);
        self::assertSame('tail>lazy-runtime', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'tail:enter',
            'handler',
            'tail:exit',
        ], $trace->all());
    }

    public function test_it_does_not_resolve_removed_lazy_class_string_middleware(): void
    {
        AutoResolvedLazyRuntimeMiddleware::$constructed = 0;

        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class implements MiddlewareInterface {
                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->remove(AutoResolvedLazyRuntimeMiddleware::class);

                        return $handler->handle($request);
                    }
                },
                AutoResolvedLazyRuntimeMiddleware::class,
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $response = $dispatcher->handle(new FakeServerRequest('/lazy-runtime-remove', 'GET'));

        self::assertSame(0, AutoResolvedLazyRuntimeMiddleware::$constructed);
        self::assertSame('tail', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([
            'tail:enter',
            'handler',
            'tail:exit',
        ], $trace->all());
    }

    public function test_it_can_use_runtime_final_handler_that_throws_and_propagates_exception_flow(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new RecordRouteMiddleware($responseFactory, $trace, 'outer'),
                new class($responseFactory) implements MiddlewareInterface {
                    public function __construct(
                        private FakeResponseFactory $responseFactory,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->setFinalHandler(new class implements RequestHandlerInterface {
                            public function handle(ServerRequestInterface $request): ResponseInterface
                            {
                                throw new \RuntimeException('Thrown by runtime final handler');
                            }
                        });

                        return $handler->handle($request);
                    }
                },
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $response = $dispatcher->handle(
            (new FakeServerRequest('/runtime-final-handler-throws', 'GET'))
                ->withAttribute('handleExceptionAt', 'outer'),
        );

        self::assertSame(560, $response->getStatusCode());
        self::assertSame('outer', $response->getHeaderLine('X-Handled-By'));
        self::assertSame('Thrown by runtime final handler', $response->getHeaderLine('X-Exception-Message'));
        self::assertSame([
            'outer:enter',
            'outer:exit',
        ], $trace->all());
    }

    public function test_it_can_bypass_outer_middleware_from_current_middleware(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new RecordRouteMiddleware($responseFactory, $trace, 'outer'),
                new class($trace) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $this->trace->add('skipper:enter');
                        $current = $request->getAttribute('routePipeline');
                        $current = is_string($current) && $current !== ''
                            ? $current . '>skipper'
                            : 'skipper';
                        $response = $handler->handle($request->withAttribute('routePipeline', $current));

                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->bypassOuter();
                        $this->trace->add('skipper:exit');

                        return $response->withAddedHeader('X-Route-Middleware', 'skipper');
                    }
                },
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $response = $dispatcher->handle(new FakeServerRequest('/bypass-outer', 'GET'));

        self::assertSame('outer>skipper>tail', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame(['tail', 'skipper'], $response->getHeader('X-Route-Middleware'));
        self::assertSame([
            'outer:enter',
            'skipper:enter',
            'tail:enter',
            'handler',
            'tail:exit',
            'skipper:exit',
        ], $trace->all());
    }

    public function test_it_uses_the_last_bypass_outer_call_as_boundary(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
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
                        $this->trace->add('outer:enter');
                        $current = $request->getAttribute('routePipeline');
                        $current = is_string($current) && $current !== ''
                            ? $current . '>outer'
                            : 'outer';

                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->bypassOuter();
                        $response = $handler->handle($request->withAttribute('routePipeline', $current));
                        $this->trace->add('outer:exit');

                        return $response->withAddedHeader('X-Route-Middleware', 'outer');
                    }
                },
                new RecordRouteMiddleware($responseFactory, $trace, 'middle'),
                new class($trace) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $this->trace->add('inner:enter');
                        $current = $request->getAttribute('routePipeline');
                        $current = is_string($current) && $current !== ''
                            ? $current . '>inner'
                            : 'inner';

                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->bypassOuter();
                        $response = $handler->handle($request->withAttribute('routePipeline', $current));
                        $this->trace->add('inner:exit');

                        return $response->withAddedHeader('X-Route-Middleware', 'inner');
                    }
                },
                new RecordRouteMiddleware($responseFactory, $trace, 'tail'),
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $response = $dispatcher->handle(new FakeServerRequest('/bypass-outer-last-call-wins', 'GET'));

        self::assertSame('outer>middle>inner>tail', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame(['tail', 'inner'], $response->getHeader('X-Route-Middleware'));
        self::assertSame([
            'outer:enter',
            'middle:enter',
            'inner:enter',
            'tail:enter',
            'handler',
            'tail:exit',
            'inner:exit',
        ], $trace->all());
    }

    public function test_bypass_outer_does_not_swallow_thrown_exceptions(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new RecordRouteMiddleware($responseFactory, $trace, 'outer'),
                new class($trace) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $this->trace->add('inner:enter');
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->bypassOuter();

                        throw new \RuntimeException('Thrown after bypassOuter');
                    }
                },
            ],
            $this->createFinalHandler($trace, $responseFactory),
        );

        $response = $dispatcher->handle(
            (new FakeServerRequest('/bypass-outer-throws', 'GET'))
                ->withAttribute('handleExceptionAt', 'outer'),
        );

        self::assertSame(560, $response->getStatusCode());
        self::assertSame('outer', $response->getHeaderLine('X-Handled-By'));
        self::assertSame('Thrown after bypassOuter', $response->getHeaderLine('X-Exception-Message'));
        self::assertSame([
            'outer:enter',
            'inner:enter',
            'outer:exit',
        ], $trace->all());
    }

    public function test_it_can_combine_bypass_outer_with_short_circuit(): void
    {
        $trace = new DispatchTrace();
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new RecordRouteMiddleware($responseFactory, $trace, 'outer'),
                new class($trace, $responseFactory) implements MiddlewareInterface {
                    public function __construct(
                        private DispatchTrace $trace,
                        private FakeResponseFactory $responseFactory,
                    ) {
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $this->trace->add('short:enter');
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $control->bypassOuter();
                        $current = $request->getAttribute('routePipeline');
                        $current = is_string($current) && $current !== ''
                            ? $current . '>short'
                            : 'short';

                        return $this->responseFactory
                            ->createResponse(204)
                            ->withHeader('X-Short-Circuit', 'yes')
                            ->withHeader('X-Route-Pipeline', $current)
                            ->withAddedHeader('X-Route-Middleware', 'short');
                    }
                },
            ],
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \LogicException('Final handler must not be called.');
                }
            },
        );

        $response = $dispatcher->handle(new FakeServerRequest('/bypass-outer-short-circuit', 'GET'));

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('yes', $response->getHeaderLine('X-Short-Circuit'));
        self::assertSame('outer>short', $response->getHeaderLine('X-Route-Pipeline'));
        self::assertSame(['short'], $response->getHeader('X-Route-Middleware'));
        self::assertSame([
            'outer:enter',
            'short:enter',
        ], $trace->all());
    }

    public function test_it_throws_when_bypass_outer_is_called_outside_middleware_fiber(): void
    {
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [],
            new class($responseFactory) implements RequestHandlerInterface {
                public function __construct(
                    private FakeResponseFactory $responseFactory,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $control = $request->getAttribute(DispatchRuntime::class);

                    if (!$control instanceof DispatchRuntime) {
                        throw new \LogicException('Dispatch control attribute is missing.');
                    }

                    $control->bypassOuter();

                    return $this->responseFactory->createResponse(200);
                }
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bypassOuter() can only be called from middleware fiber.');

        $dispatcher->handle(new FakeServerRequest('/bypass-outer-outside-fiber', 'GET'));
    }

    public function test_it_throws_when_bypass_outer_is_called_from_nested_user_fiber(): void
    {
        $responseFactory = new FakeResponseFactory();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class implements MiddlewareInterface {
                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        $control = $request->getAttribute(DispatchRuntime::class);

                        if (!$control instanceof DispatchRuntime) {
                            throw new \LogicException('Dispatch control attribute is missing.');
                        }

                        $fiber = new \Fiber(static function () use ($control): void {
                            $control->bypassOuter();
                        });

                        $fiber->start();

                        return $handler->handle($request);
                    }
                },
            ],
            new class($responseFactory) implements RequestHandlerInterface {
                public function __construct(
                    private FakeResponseFactory $responseFactory,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->responseFactory->createResponse(200);
                }
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bypassOuter() can only be called from middleware fiber.');

        $dispatcher->handle(new FakeServerRequest('/bypass-outer-nested-fiber', 'GET'));
    }

    public function test_it_throws_on_reentrant_handle_of_the_same_instance(): void
    {
        $dispatcher = null;
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [
                new class($dispatcher) implements MiddlewareInterface {
                    private ?MiddlewareDispatcher $dispatcher;

                    public function __construct(?MiddlewareDispatcher &$dispatcher)
                    {
                        $this->dispatcher = &$dispatcher;
                    }

                    public function process(
                        ServerRequestInterface $request,
                        RequestHandlerInterface $handler,
                    ): ResponseInterface {
                        if (!$this->dispatcher instanceof MiddlewareDispatcher) {
                            throw new \LogicException('Dispatcher instance is missing.');
                        }

                        return $this->dispatcher->handle($request->withAttribute('nested', true));
                    }
                },
            ],
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \LogicException('Final handler must not be called.');
                }
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reentrant handle() calls on the same dispatcher instance are not supported.');

        $dispatcher->handle(new FakeServerRequest('/reentrant-handle', 'GET'));
    }

    public function test_it_allows_final_handler_to_mutate_runtime_middlewares_only(): void
    {
        $responseFactory = new FakeResponseFactory();
        $trace = new DispatchTrace();
        $dispatcher = new MiddlewareDispatcher(
            new TestContainer(),
            [],
            new class($responseFactory, $trace) implements RequestHandlerInterface {
                public function __construct(
                    private FakeResponseFactory $responseFactory,
                    private DispatchTrace $trace,
                ) {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $control = $request->getAttribute(DispatchRuntime::class);

                    if (!$control instanceof DispatchRuntime) {
                        throw new \LogicException('Dispatch control attribute is missing.');
                    }

                    $control->append(new RecordRouteMiddleware($this->responseFactory, $this->trace, 'late-append'));
                    $control->prepend(new RecordRouteMiddleware($this->responseFactory, $this->trace, 'late-prepend'));

                    return $this->responseFactory->createResponse(200);
                }
            },
        );

        $firstResponse = $dispatcher->handle(new FakeServerRequest('/final-handler-mutation-first', 'GET'));
        $secondResponse = $dispatcher->handle(new FakeServerRequest('/final-handler-mutation-second', 'GET'));

        self::assertSame('', $firstResponse->getHeaderLine('X-Route-Pipeline'));
        self::assertSame('', $secondResponse->getHeaderLine('X-Route-Pipeline'));
        self::assertSame([], $trace->all());
    }

    public function test_it_sets_dispatch_control_on_default_request_attribute_when_missing(): void
    {
        $responseFactory = new FakeResponseFactory();
        $container = new TestContainer();
        $capturedRequest = null;
        $dispatcher = new MiddlewareDispatcher(
            $container,
            [],
            new class($responseFactory, $capturedRequest) implements RequestHandlerInterface {
                private ?ServerRequestInterface $capturedRequest;

                public function __construct(
                    private FakeResponseFactory $responseFactory,
                    ?ServerRequestInterface &$capturedRequest,
                ) {
                    $this->capturedRequest = &$capturedRequest;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->capturedRequest = $request;

                    return $this->responseFactory->createResponse(200);
                }
            },
        );

        $dispatcher->handle(new FakeServerRequest('/dispatcher-attribute', 'GET'));

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertInstanceOf(DispatchRuntime::class, $capturedRequest->getAttribute(DispatchRuntime::class));
        self::assertNotSame($dispatcher, $capturedRequest->getAttribute(DispatchRuntime::class));
    }

    public function test_it_sets_dispatch_control_on_custom_request_attribute_when_missing(): void
    {
        $responseFactory = new FakeResponseFactory();
        $container = new TestContainer();
        $capturedRequest = null;
        $attributeName = 'currentDispatcher';
        $dispatcher = new MiddlewareDispatcher(
            $container,
            [],
            new class($responseFactory, $capturedRequest) implements RequestHandlerInterface {
                private ?ServerRequestInterface $capturedRequest;

                public function __construct(
                    private FakeResponseFactory $responseFactory,
                    ?ServerRequestInterface &$capturedRequest,
                ) {
                    $this->capturedRequest = &$capturedRequest;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->capturedRequest = $request;

                    return $this->responseFactory->createResponse(200);
                }
            },
            $attributeName,
        );

        $dispatcher->handle(new FakeServerRequest('/dispatcher-attribute-custom', 'GET'));

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertInstanceOf(DispatchRuntime::class, $capturedRequest->getAttribute($attributeName));
        self::assertNotSame($dispatcher, $capturedRequest->getAttribute($attributeName));
        self::assertNull($capturedRequest->getAttribute(DispatchRuntime::class));
    }

    public function test_it_does_not_override_existing_request_attribute(): void
    {
        $responseFactory = new FakeResponseFactory();
        $container = new TestContainer();
        $capturedRequest = null;
        $existingDispatcher = new \stdClass();
        $dispatcher = new MiddlewareDispatcher(
            $container,
            [],
            new class($responseFactory, $capturedRequest) implements RequestHandlerInterface {
                private ?ServerRequestInterface $capturedRequest;

                public function __construct(
                    private FakeResponseFactory $responseFactory,
                    ?ServerRequestInterface &$capturedRequest,
                ) {
                    $this->capturedRequest = &$capturedRequest;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->capturedRequest = $request;

                    return $this->responseFactory->createResponse(200);
                }
            },
        );

        $dispatcher->handle(
            (new FakeServerRequest('/dispatcher-attribute-existing', 'GET'))
                ->withAttribute(DispatchRuntime::class, $existingDispatcher),
        );

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertSame($existingDispatcher, $capturedRequest->getAttribute(DispatchRuntime::class));
        self::assertNotSame($dispatcher, $capturedRequest->getAttribute(DispatchRuntime::class));
    }

    public function test_it_does_not_set_request_attribute_when_attribute_name_is_empty(): void
    {
        $responseFactory = new FakeResponseFactory();
        $container = new TestContainer();
        $capturedRequest = null;
        $dispatcher = new MiddlewareDispatcher(
            $container,
            [],
            new class($responseFactory, $capturedRequest) implements RequestHandlerInterface {
                private ?ServerRequestInterface $capturedRequest;

                public function __construct(
                    private FakeResponseFactory $responseFactory,
                    ?ServerRequestInterface &$capturedRequest,
                ) {
                    $this->capturedRequest = &$capturedRequest;
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    $this->capturedRequest = $request;

                    return $this->responseFactory->createResponse(200);
                }
            },
            '',
        );

        $dispatcher->handle(new FakeServerRequest('/dispatcher-attribute-empty', 'GET'));

        self::assertInstanceOf(ServerRequestInterface::class, $capturedRequest);
        self::assertNull($capturedRequest->getAttribute(DispatchRuntime::class));
        self::assertArrayNotHasKey('', $capturedRequest->getAttributes());
    }

    public function test_it_resolves_middleware_list_lazily_on_handle(): void
    {
        $container = new TestContainer([
            InvalidResolvedLazyMiddleware::class => new InvalidResolvedLazyMiddleware(),
        ]);
        $dispatcher = new MiddlewareDispatcher(
            $container,
            [InvalidResolvedLazyMiddleware::class],
            new class implements RequestHandlerInterface {
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    throw new \LogicException('Final handler must not be called.');
                }
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Resolved middleware ' . InvalidResolvedLazyMiddleware::class . ' must implement');

        $dispatcher->handle(new FakeServerRequest('/lazy', 'GET'));
    }

    public function test_it_does_not_resolve_unreached_middleware_after_short_circuit(): void
    {
        $responseFactory = new FakeResponseFactory();
        $container = new TestContainer([
            InvalidResolvedLazyMiddleware::class => new InvalidResolvedLazyMiddleware(),
        ]);
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
                InvalidResolvedLazyMiddleware::class,
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

    private function createFinalHandler(
        DispatchTrace $trace,
        FakeResponseFactory $responseFactory,
    ): RequestHandlerInterface {
        return new class($trace, $responseFactory) implements RequestHandlerInterface {
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
        };
    }

    private function createTaggedFinalHandler(
        FakeResponseFactory $responseFactory,
        string $name,
    ): RequestHandlerInterface {
        return new class($responseFactory, $name) implements RequestHandlerInterface {
            public function __construct(
                private FakeResponseFactory $responseFactory,
                private string $name,
            ) {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->responseFactory
                    ->createResponse(200)
                    ->withHeader('X-Final-Handler', $this->name);
            }
        };
    }
}
