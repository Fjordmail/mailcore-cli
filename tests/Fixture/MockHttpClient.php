<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli\Tests\Fixture;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Recording PSR-18 client for CLI tests. Replays a queue of responses and keeps
 * the last request so tests can assert on the path/query a command produced.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];

    /** @var list<ResponseInterface> */
    private array $responses;

    public function __construct(ResponseInterface ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return count($this->responses) > 1 ? array_shift($this->responses) : $this->responses[0];
    }

    public function lastPath(): string
    {
        $uri = (string) $this->requests[array_key_last($this->requests)]->getUri();

        return (string) preg_replace('#^/[^/]+#', '', (string) parse_url($uri, PHP_URL_PATH));
    }

    /** @return array<string, string> */
    public function lastQuery(): array
    {
        $uri = (string) $this->requests[array_key_last($this->requests)]->getUri();
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    public function callCount(): int
    {
        return count($this->requests);
    }
}
