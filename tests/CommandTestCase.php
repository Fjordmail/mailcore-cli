<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli\Tests;

use Inboxcom\Mailcore\Cli\ApiCommand;
use Inboxcom\Mailcore\Cli\Commands;
use Inboxcom\Mailcore\Cli\Tests\Fixture\MockHttpClient;
use Inboxcom\Mailcore\MailcoreClient;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

abstract class CommandTestCase extends TestCase
{
    protected MockHttpClient $http;

    /**
     * Build the named command wired to a mock transport replaying $responses,
     * and return a CommandTester ready to execute it.
     */
    protected function tester(string $name, Response ...$responses): CommandTester
    {
        if ($responses === []) {
            $responses = [new Response(200, [], '')];
        }
        $this->http = new MockHttpClient(...$responses);
        $client = new MailcoreClient('TESTKEY', MailcoreClient::DEFAULT_BASE_URI, $this->http, new HttpFactory());

        foreach (Commands::all(static fn (): MailcoreClient => $client) as $command) {
            if ($command->getName() === $name) {
                return new CommandTester($command);
            }
        }

        throw new \RuntimeException(sprintf('Unknown command "%s".', $name));
    }

    protected static function json(mixed $value): Response
    {
        return new Response(200, [], (string) json_encode($value));
    }

    protected static function error(int $status, string $statusmsg): Response
    {
        return new Response($status, [], (string) json_encode(['statusmsg' => $statusmsg]));
    }

    /** A response with a raw, verbatim body — e.g. an endpoint that returns data alongside a non-2xx status. */
    protected static function raw(string $body, int $status = 200): Response
    {
        return new Response($status, [], $body);
    }
}
