<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Container;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

final class TestContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
