<?php

declare(strict_types=1);

namespace PactivaSign\Laravel\Testing;

use Psr\Http\Message\RequestInterface;

/**
 * An API request the fake client received.
 */
final class RecordedRequest
{
    /** @var array<string, mixed> */
    public readonly array $query;

    public readonly string $body;

    public function __construct(public readonly RequestInterface $request)
    {
        parse_str($request->getUri()->getQuery(), $query);
        /** @var array<string, mixed> $query */
        $this->query = $query;
        $this->body = (string) $request->getBody();
    }

    public function method(): string
    {
        return $this->request->getMethod();
    }

    public function path(): string
    {
        return $this->request->getUri()->getPath();
    }

    /** True when "METHOD /path" matches, with * as a wildcard: `is('POST /api/v1/envelopes')`. */
    public function is(string $pattern): bool
    {
        [$method, $path] = str_contains($pattern, ' ') ? explode(' ', $pattern, 2) : ['*', $pattern];

        return fnmatch(strtoupper($method), $this->method()) && fnmatch($path, $this->path());
    }

    /**
     * The JSON body, decoded.
     *
     * @return array<mixed>
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): string
    {
        return $this->request->getHeaderLine($name);
    }
}
