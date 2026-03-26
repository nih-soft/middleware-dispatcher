<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

final readonly class FakeUri implements UriInterface
{
    public function __construct(
        private readonly string $path,
        private readonly string $scheme = '',
        private readonly string $host = '',
        private readonly ?int $port = null,
    ) {
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getAuthority(): string
    {
        if ($this->host === '') {
            return '';
        }

        if ($this->port === null) {
            return $this->host;
        }

        return $this->host . ':' . $this->port;
    }

    public function getUserInfo(): string
    {
        return '';
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): ?int
    {
        return $this->port;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getQuery(): string
    {
        return '';
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme(string $scheme): UriInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withHost(string $host): UriInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withPort(?int $port): UriInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withPath(string $path): UriInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withQuery(string $query): UriInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withFragment(string $fragment): UriInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function __toString(): string
    {
        if ($this->scheme === '' || $this->host === '') {
            return $this->path;
        }

        return $this->scheme . '://' . $this->getAuthority() . $this->path;
    }
}
