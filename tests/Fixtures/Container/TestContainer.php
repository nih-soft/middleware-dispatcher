<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Container;

use Psr\Container\ContainerInterface;
use ReflectionClass;

final readonly class TestContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     */
    public function __construct(
        private array $entries = [],
    ) {
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->entries)) {
            return $this->entries[$id];
        }

        if (!class_exists($id)) {
            throw new TestNotFoundException(sprintf('Entry "%s" was not found.', $id));
        }

        $reflection = new ReflectionClass($id);

        if (!$reflection->isInstantiable()) {
            throw new TestContainerException(sprintf('Class "%s" is not instantiable.', $id));
        }

        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new TestContainerException(sprintf(
                'Class "%s" cannot be auto-instantiated by the test container.',
                $id,
            ));
        }

        return $reflection->newInstance();
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries) || class_exists($id);
    }
}
