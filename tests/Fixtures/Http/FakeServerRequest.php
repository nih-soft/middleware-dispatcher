<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Http;

use InvalidArgumentException;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

final class FakeServerRequest implements ServerRequestInterface
{
    private array $attributes = [];

    private array $queryParams;

    public function __construct(
        private readonly string $path,
        private readonly string $method,
        private readonly string $scheme = '',
        private readonly string $host = '',
        private readonly ?int $port = null,
        array $queryParams = [],
    ) {
        $this->queryParams = $queryParams;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): MessageInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getHeaders(): array
    {
        return [];
    }

    public function hasHeader(string $name): bool
    {
        return false;
    }

    public function getHeader(string $name): array
    {
        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return '';
    }

    public function withHeader(string $name, $value): MessageInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withAddedHeader(string $name, $value): MessageInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withoutHeader(string $name): MessageInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getBody(): StreamInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function withBody(StreamInterface $body): MessageInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getRequestTarget(): string
    {
        return $this->path;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getUri(): UriInterface
    {
        return new FakeUri($this->path, $this->scheme, $this->host, $this->port);
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getServerParams(): array
    {
        return [];
    }

    public function getCookieParams(): array
    {
        return [];
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;

        return $clone;
    }

    public function getUploadedFiles(): array
    {
        return [];
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getParsedBody(): mixed
    {
        return null;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        throw new InvalidArgumentException('Not implemented.');
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name, $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function withAttribute(string $name, $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);

        return $clone;
    }
}
