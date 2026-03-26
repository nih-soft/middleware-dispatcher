<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Container;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class TestNotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
