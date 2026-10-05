<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli\Tests;

use Symfony\Component\Console\Command\Command;

final class CommandBehaviorTest extends CommandTestCase
{
    public function testUsersListRendersAddressesAndCount(): void
    {
        $tester = $this->tester('users:list', self::json(['a.demo.test@example.com', 'b.demo.test@example.com']));

        $tester->execute(['--filter' => '*']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('a.demo.test@example.com', $tester->getDisplay());
        self::assertStringContainsString('2 user(s).', $tester->getDisplay());
        self::assertSame('/users/list', $this->http->lastPath());
        self::assertSame('*', $this->http->lastQuery()['filter']);
    }

    public function testUsersGetRendersDetail(): void
    {
        $tester = $this->tester('users:get', self::json([
            'active' => 1, 'imap' => 1, 'pop3' => 0, 'mailbox_quota' => 15360,
            'mailboxplan_name' => 'Demo Plan', 'mailboxplan_id' => 4, 'spammer' => 0, 'weakpass' => 1,
            'flags' => ['weakpass'],
        ]));

        $tester->execute(['email' => 'holger.demo.test@example.com']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        self::assertStringContainsString('holger.demo.test@example.com', $display);
        // Fields are rendered generically from the raw payload: humanised key + raw value.
        self::assertStringContainsString('Mailboxplan name', $display);
        self::assertStringContainsString('Demo Plan', $display);
        self::assertStringContainsString('Flags', $display);
        self::assertStringContainsString('weakpass', $display);
        self::assertSame(['user' => 'holger.demo.test@example.com'], $this->http->lastQuery());
    }

    public function testUsersGetRendersLongArrayOnePerLine(): void
    {
        // A long array (e.g. password_changes) must render one entry per line, not
        // comma-joined onto a single cell — the latter blows the definition-list
        // column out to the full width and wrecks the table's alignment.
        $changes = ['2024-01-01T00:00:00Z', '2024-02-02T00:00:00Z', '2024-03-03T00:00:00Z'];
        $tester = $this->tester('users:get', self::json([
            'active' => 1,
            'password_changes' => $changes,
        ]));

        $tester->execute(['email' => 'holger.demo.test@example.com']);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        foreach ($changes as $ts) {
            self::assertStringContainsString($ts, $display);
        }
        // Not collapsed onto one comma-joined line.
        self::assertStringNotContainsString($changes[0] . ', ' . $changes[1], $display);
    }

    public function testUsersAddWithPasswordOptionSendsExpectedRequest(): void
    {
        $tester = $this->tester('users:add', self::json(null));

        $tester->execute([
            'email' => 'newbie.demo.test@example.com',
            'plan' => '4',
            '--password' => 'P@ssw0rd123',
            '--deactivated' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame('/users/add', $this->http->lastPath());
        self::assertSame([
            'email' => 'newbie.demo.test@example.com',
            'password' => 'P@ssw0rd123',
            'mailboxplan' => '4',
            'deactivated' => '1',
        ], $this->http->lastQuery());
    }

    public function testUsersAddPromptsForPasswordWhenOmitted(): void
    {
        $tester = $this->tester('users:add', self::json(null));
        $tester->setInputs(['Pr0mptedPass!']);

        $tester->execute(['email' => 'prompt.demo.test@example.com', 'plan' => '4']);

        $tester->assertCommandIsSuccessful();
        self::assertSame('Pr0mptedPass!', $this->http->lastQuery()['password']);
    }

    public function testUsersCountPrintsNumber(): void
    {
        $tester = $this->tester('users:count', self::json(42));

        $tester->execute(['--domain' => 'example.com']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('42', $tester->getDisplay());
    }

    public function testVerifyPasswordReturnsFailureExitCodeWhenIncorrect(): void
    {
        $tester = $this->tester('users:verify-password', self::error(406, 'Password is not correct'));

        $tester->execute(['email' => 'a.demo.test@example.com', '--password' => 'wrong']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('incorrect', strtolower($tester->getDisplay()));
    }

    public function testRblLookupReturnsFailureExitCodeAndNamesListsWhenListed(): void
    {
        $tester = $this->tester('mailfilter:rbl-lookup', self::raw('{"cbl.mailcore.net":"LISTED","psbl.surriel.com":"CLEAN"}', 409));

        $tester->execute(['ip' => '127.0.0.2']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('LISTED', $display);
        self::assertStringContainsString('cbl.mailcore.net', $display);
        self::assertStringNotContainsString('psbl.surriel.com', $display, 'only the flagging lists are named');
    }

    public function testCdlLookupReturnsFailureExitCodeWhenListed(): void
    {
        $tester = $this->tester('mailfilter:cdl-lookup', self::error(409, '[ip] was found listed on CDL'));

        $tester->execute(['ip' => '8.8.8.8']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('LISTED', $tester->getDisplay());
    }

    public function testBplLookupReturnsFailureExitCodeWhenListed(): void
    {
        $tester = $this->tester('mailfilter:bpl-lookup', self::error(409, 'Host found on BPL'));

        $tester->execute(['ip' => '8.8.8.8']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('BLOCKED', $tester->getDisplay());
    }

    public function testBplLookupRendersBlockDetails(): void
    {
        $body = '{"statusmsg":"Host found on BPL","date_added":"2026-08-11 20:43:43",'
            . '"timeframe_min":30,"sample":["fischer10@indamail.hu","ogitrew@citromail.hu"]}';
        $tester = $this->tester('mailfilter:bpl-lookup', self::raw($body, 409));

        $tester->execute(['ip' => '1.0.210.163']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('2026-08-11 20:43:43', $display);
        self::assertStringContainsString('30 min', $display);
        self::assertStringContainsString('fischer10@indamail.hu', $display);
        self::assertStringContainsString('ogitrew@citromail.hu', $display);
    }

    public function testLogLoginRejectsInvalidServiceWithoutCallingApi(): void
    {
        $tester = $this->tester('users:log-login', self::json(null));

        $tester->execute(['email' => 'a.demo.test@example.com', 'service' => 'ftp', 'ip' => '8.8.8.8']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(0, $this->http->callCount(), 'No HTTP request should be made for an invalid service');
    }

    public function testApiErrorIsRenderedAsCleanFailure(): void
    {
        $tester = $this->tester('users:get', self::error(404, 'User not found'));

        $tester->execute(['email' => 'ghost.demo.test@example.com']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('User not found', $tester->getDisplay());
    }

    public function testMailboxplansListRendersTable(): void
    {
        $tester = $this->tester('mailboxplans:list', self::json([
            ['id' => 4, 'name' => 'Demo Plan', 'mailbox_quota' => 15360, 'imap' => 1, 'pop3' => 1, 'smtp' => 1, 'webmail' => 0, 'aliases' => 5, 'forwards' => 0, 'date_created' => '2014-02-13 13:06:57'],
        ]));

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Demo Plan', $tester->getDisplay());
        self::assertStringContainsString('1 plan(s).', $tester->getDisplay());
    }

    public function testDatadumpFetchWritesToOutputFile(): void
    {
        $binary = "\x1f\x8b\x08PGP-bytes";
        $tester = $this->tester('datadump:fetch', new \GuzzleHttp\Psr7\Response(200, [], $binary));

        $path = tempnam(sys_get_temp_dir(), 'mailcore-dump-');
        self::assertIsString($path);

        try {
            $tester->execute(['--output' => $path]);

            $tester->assertCommandIsSuccessful();
            self::assertSame($binary, (string) file_get_contents($path));
            self::assertStringContainsString('bytes', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }
}
