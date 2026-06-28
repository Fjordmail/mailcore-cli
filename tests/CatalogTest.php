<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli\Tests;

use Inboxcom\Mailcore\Cli\ApiCommand;
use Inboxcom\Mailcore\Cli\Application;
use Inboxcom\Mailcore\Cli\Commands;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    public function testEveryCommandIsAnApiCommand(): void
    {
        $commands = Commands::all();

        self::assertNotEmpty($commands);
        self::assertContainsOnlyInstancesOf(ApiCommand::class, $commands);
    }

    public function testCommandNamesAreUnique(): void
    {
        $names = array_map(static fn (ApiCommand $c): string => (string) $c->getName(), Commands::all());

        self::assertSame(array_values(array_unique($names)), $names);
    }

    /**
     * One command per API operation, plus `users:get` which shares the
     * /users/list endpoint with `users:list` — 56 in total.
     */
    public function testGroupCountsMatchTheApiSurface(): void
    {
        $counts = [];
        foreach (Commands::all() as $command) {
            $group = explode(':', (string) $command->getName())[0];
            $counts[$group] = ($counts[$group] ?? 0) + 1;
        }
        ksort($counts);

        self::assertSame([
            'datadump' => 1,
            'domains' => 5,
            'mailboxplans' => 1,
            'mailfilter' => 12,
            'reports' => 1,
            'users' => 37,
        ], $counts);
        self::assertSame(57, array_sum($counts));
    }

    public function testApplicationRegistersTheCatalogue(): void
    {
        $app = new Application();

        self::assertTrue($app->has('users:list'));
        self::assertTrue($app->has('mailfilter:rbl-lookup'));
        self::assertTrue($app->has('mailfilter:cdl-lookup'));
        self::assertTrue($app->has('datadump:fetch'));
    }
}
