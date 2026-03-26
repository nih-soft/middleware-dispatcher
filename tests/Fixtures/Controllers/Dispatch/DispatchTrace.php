<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Controllers\Dispatch;

final class DispatchTrace
{
    /**
     * @var list<string>
     */
    private array $events = [];

    public function add(string $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->events;
    }
}
