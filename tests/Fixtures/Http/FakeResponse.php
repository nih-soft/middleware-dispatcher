<?php

declare(strict_types=1);

namespace NIH\MiddlewareDispatcher\Tests\Fixtures\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

final class FakeResponse implements ResponseInterface
{
    /**
     * @var array<string, list<string>>
     */
    private array $headers = [];

    /**
     * @var array<string, string>
     */
    private array $headerNames = [];

    private StreamInterface $body;

    public function __construct(
        private int $statusCode = 200,
        private string $reasonPhrase = '',
        private string $protocolVersion = '1.1',
        ?StreamInterface $body = null,
    ) {
        $this->body = $body ?? new FakeStream();
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function withProtocolVersion(string $version): ResponseInterface
    {
        $clone = clone $this;
        $clone->protocolVersion = $version;

        return $clone;
    }

    public function getHeaders(): array
    {
        $headers = [];

        foreach ($this->headers as $name => $values) {
            $headers[$this->headerNames[$name]] = $values;
        }

        return $headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        $clone->headerNames[$normalized] = $name;
        $clone->headers[$normalized] = $this->normalizeHeaderValues($value);

        return $clone;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        $clone->headerNames[$normalized] = $name;
        $clone->headers[$normalized] = [
            ...($clone->headers[$normalized] ?? []),
            ...$this->normalizeHeaderValues($value),
        ];

        return $clone;
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        $clone = clone $this;
        $normalized = strtolower($name);
        unset($clone->headers[$normalized], $clone->headerNames[$normalized]);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->statusCode = $code;
        $clone->reasonPhrase = $reasonPhrase;

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * @param string|list<string> $value
     * @return list<string>
     */
    private function normalizeHeaderValues(string|array $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_map(static fn(mixed $item): string => (string) $item, $values);
    }
}
