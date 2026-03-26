<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Http;

use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

final class FakeStream implements StreamInterface
{
    private int $position = 0;

    public function __construct(
        private string $contents = '',
    ) {
    }

    public function __toString(): string
    {
        return $this->contents;
    }

    public function close(): void
    {
        $this->detach();
    }

    public function detach(): null
    {
        $this->contents = '';
        $this->position = 0;

        return null;
    }

    public function getSize(): int
    {
        return strlen($this->contents);
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= strlen($this->contents);
    }

    public function isSeekable(): bool
    {
        return true;
    }

    public function seek(int $offset, int $whence = SEEK_SET): void
    {
        $target = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->contents) + $offset,
            default => throw new InvalidArgumentException('Invalid seek mode.'),
        };

        if ($target < 0) {
            throw new InvalidArgumentException('Invalid seek offset.');
        }

        $this->position = $target;
    }

    public function rewind(): void
    {
        $this->position = 0;
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function write(string $string): int
    {
        $prefix = substr($this->contents, 0, $this->position);
        $suffix = substr($this->contents, $this->position + strlen($string));
        $this->contents = $prefix . $string . $suffix;
        $this->position += strlen($string);

        return strlen($string);
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->contents, $this->position, $length);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function getContents(): string
    {
        $chunk = substr($this->contents, $this->position);
        $this->position = strlen($this->contents);

        return $chunk;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
