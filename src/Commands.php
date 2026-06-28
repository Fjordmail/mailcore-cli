<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli;

use Inboxcom\Mailcore\MailcoreClient;
use Inboxcom\Mailcore\Model\Service;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The full command catalogue: one {@see ApiCommand} per Mailcore API operation,
 * grouped by resource. Keeping every command in one declarative registry keeps
 * the 54 operations consistent and easy to scan, instead of 54 near-identical
 * class files.
 */
final class Commands
{
    /**
     * @param \Closure(): MailcoreClient|null $clientProvider Override client construction (tests inject a mock).
     *
     * @return list<ApiCommand>
     */
    public static function all(?\Closure $clientProvider = null): array
    {
        $commands = [
            ...self::domains(),
            ...self::users(),
            ...self::mailboxplans(),
            ...self::mailfilter(),
            ...self::reports(),
            ...self::datadump(),
        ];

        if ($clientProvider !== null) {
            foreach ($commands as $command) {
                $command->setClientProvider($clientProvider);
            }
        }

        return $commands;
    }

    /** @return list<ApiCommand> */
    private static function domains(): array
    {
        return [
            new ApiCommand('domains:list', 'List domains, optionally filtered', [], [
                ['domain', 'value', 'Look up a single domain'],
                ['limit', 'value', 'Offset and limit, e.g. "0,100"'],
                ['filter', 'value', 'Search filter, e.g. "*"'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                return self::strings($io, $c->domains()->list(
                    $in->getOption('domain'),
                    $in->getOption('limit'),
                    $in->getOption('filter'),
                ), 'domain(s)');
            }),

            new ApiCommand('domains:add', 'Add a domain', [['domain', true, 'Domain name']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->domains()->add((string) $in->getArgument('domain'));

                    return self::ok($io, sprintf('Added domain %s.', $in->getArgument('domain')));
                }),

            new ApiCommand('domains:add-alias', 'Add an alias domain for an existing domain', [
                ['domain', true, 'Existing (target) domain'],
                ['alias', true, 'Alias (source) domain'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->domains()->addAlias((string) $in->getArgument('domain'), (string) $in->getArgument('alias'));

                return self::ok($io, sprintf('Added alias %s -> %s.', $in->getArgument('alias'), $in->getArgument('domain')));
            }),

            new ApiCommand('domains:remove', 'Remove a domain', [['domain', true, 'Domain name']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->domains()->remove((string) $in->getArgument('domain'));

                    return self::ok($io, sprintf('Removed domain %s.', $in->getArgument('domain')));
                }),

            new ApiCommand('domains:count', 'Count all domains on the service', [], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $io->writeln((string) $c->domains()->count());

                    return Command::SUCCESS;
                }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function users(): array
    {
        return [
            new ApiCommand('users:list', 'List mailboxes, optionally filtered', [], [
                ['filter', 'value', 'Search filter, e.g. "*"'],
                ['limit', 'value', 'Offset and limit, e.g. "0,100"'],
                ['plan', 'value', 'Restrict to a mailbox plan id'],
                ['extended', 'none', 'Return detailed records (email/active/plan) instead of addresses'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                if ($in->getOption('extended')) {
                    $rows = array_map(
                        static fn ($u): array => [
                            $u->email,
                            self::yn($u->active),
                            $u->mailboxplanName !== '' ? sprintf('%s (#%d)', $u->mailboxplanName, $u->mailboxplanId) : sprintf('#%d', $u->mailboxplanId),
                        ],
                        $c->users()->list($in->getOption('filter'), $in->getOption('limit'), self::planId($in), true),
                    );

                    return self::table($io, ['Email', 'Active', 'Plan'], $rows, 'user(s)');
                }

                return self::strings($io, $c->users()->list(
                    $in->getOption('filter'),
                    $in->getOption('limit'),
                    self::planId($in),
                ), 'user(s)');
            }),

            new ApiCommand('users:get', 'Show full detail for one mailbox', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    // The single-user lookup always returns the full record (the API
                    // ignores `extended` here). Render whatever the server sent, keyed
                    // by its own field names, so new fields surface without a code change.
                    $email = (string) $in->getArgument('email');
                    $rows = [['Email' => $email]];
                    foreach ($c->users()->get($email)->raw as $key => $value) {
                        $rows[] = [self::humanize($key) => self::display($value)];
                    }
                    $io->definitionList(...$rows);

                    return Command::SUCCESS;
                }),

            new ApiCommand('users:add', 'Add a new mailbox', [
                ['email', true, 'E-mail address'],
                ['plan', true, 'Mailbox plan id'],
            ], [
                ['password', 'value', 'Password (prompted securely if omitted)'],
                ['deactivated', 'none', 'Create in a deactivated state'],
                ['ignore-reservation', 'none', 'Add even if the address is reserved'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $email = (string) $in->getArgument('email');
                $c->users()->add(
                    $email,
                    self::password($in, $io, 'Password for ' . $email),
                    (int) $in->getArgument('plan'),
                    deactivated: (bool) $in->getOption('deactivated'),
                    ignoreReservation: (bool) $in->getOption('ignore-reservation'),
                );

                return self::ok($io, sprintf('Added %s on plan %s.', $email, $in->getArgument('plan')));
            }),

            new ApiCommand('users:remove', 'Remove a mailbox and all its data', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->users()->remove((string) $in->getArgument('email'));

                    return self::ok($io, sprintf('Removed %s.', $in->getArgument('email')));
                }),

            new ApiCommand('users:add-alias', 'Add an alias for a mailbox', [
                ['email', true, 'Primary e-mail address'],
                ['alias', true, 'Alias address'],
            ], [['ignore-reservation', 'none', 'Add even if the alias is reserved']],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->users()->addAlias(
                        (string) $in->getArgument('email'),
                        (string) $in->getArgument('alias'),
                        (bool) $in->getOption('ignore-reservation'),
                    );

                    return self::ok($io, sprintf('Added alias %s -> %s.', $in->getArgument('alias'), $in->getArgument('email')));
                }),

            new ApiCommand('users:remove-alias', 'Remove an alias', [
                ['email', true, 'Primary e-mail address'],
                ['alias', true, 'Alias address'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->removeAlias((string) $in->getArgument('email'), (string) $in->getArgument('alias'));

                return self::ok($io, sprintf('Removed alias %s.', $in->getArgument('alias')));
            }),

            new ApiCommand('users:lookup-alias', 'Resolve an alias to its primary address', [['alias', true, 'Alias address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $io->writeln($c->users()->lookupAlias((string) $in->getArgument('alias')));

                    return Command::SUCCESS;
                }),

            new ApiCommand('users:list-aliases', 'List a mailbox\'s alias addresses', [['email', true, 'Primary e-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    return self::strings($io, $c->users()->listAliases((string) $in->getArgument('email')), 'alias(es)');
                }),

            new ApiCommand('users:add-forward', 'Add a forwarding policy', [
                ['email', true, 'Source mailbox'],
                ['forward', true, 'Destination address'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->addForward((string) $in->getArgument('email'), (string) $in->getArgument('forward'));

                return self::ok($io, sprintf('Forwarding %s -> %s.', $in->getArgument('email'), $in->getArgument('forward')));
            }),

            new ApiCommand('users:remove-forward', 'Remove a forwarding policy', [
                ['email', true, 'Source mailbox'],
                ['forward', true, 'Destination address'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->removeForward((string) $in->getArgument('email'), (string) $in->getArgument('forward'));

                return self::ok($io, 'Forwarding policy removed.');
            }),

            new ApiCommand('users:list-forwards', 'List a mailbox\'s forwarding destinations', [['email', true, 'Source mailbox']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    return self::strings($io, $c->users()->listForwards((string) $in->getArgument('email')), 'forward(s)');
                }),

            new ApiCommand('users:check-availability', 'Check whether an address is available', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $email = (string) $in->getArgument('email');
                    $io->writeln($c->users()->isAvailable($email)
                        ? sprintf('<info>%s is available.</info>', $email)
                        : sprintf('<comment>%s is not available.</comment>', $email));

                    return Command::SUCCESS;
                }),

            new ApiCommand('users:check-reservation', 'Check whether an address is reserved', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $email = (string) $in->getArgument('email');
                    $io->writeln($c->users()->isReserved($email)
                        ? sprintf('<comment>%s is reserved.</comment>', $email)
                        : sprintf('<info>%s is not reserved.</info>', $email));

                    return Command::SUCCESS;
                }),

            new ApiCommand('users:test-password', 'Test a password against the complexity policy', [], [
                ['password', 'value', 'Password (prompted securely if omitted)'],
                ['email', 'value', 'Also check re-use against this mailbox'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->testPasswordComplexity(
                    self::password($in, $io, 'Password to test'),
                    $in->getOption('email'),
                );

                return self::ok($io, 'Password meets the complexity policy.');
            }),

            new ApiCommand('users:new-password', 'Set a new password (also clears spammer/weakpass)', [['email', true, 'E-mail address']], [
                ['password', 'value', 'Password (prompted securely if omitted)'],
                ['no-reset-flags', 'none', 'Do not clear spammer/weakpass flags'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $email = (string) $in->getArgument('email');
                $c->users()->newPassword(
                    $email,
                    self::password($in, $io, 'New password for ' . $email),
                    (bool) $in->getOption('no-reset-flags'),
                );

                return self::ok($io, sprintf('Password updated for %s.', $email));
            }),

            new ApiCommand('users:verify-password', 'Verify a password is correct', [['email', true, 'E-mail address']], [
                ['password', 'value', 'Password (prompted securely if omitted)'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $email = (string) $in->getArgument('email');
                $ok = $c->users()->verifyPassword($email, self::password($in, $io, 'Password for ' . $email));
                $io->writeln($ok ? '<info>Password is correct.</info>' : '<comment>Password is incorrect.</comment>');

                return $ok ? Command::SUCCESS : Command::FAILURE;
            }),

            new ApiCommand('users:get-sieve', 'Print the active Sieve script', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $io->writeln($c->users()->getActiveSieveScript((string) $in->getArgument('email')));

                    return Command::SUCCESS;
                }),

            new ApiCommand('users:new-plan', 'Move a mailbox to a different plan', [
                ['email', true, 'E-mail address'],
                ['plan', true, 'New mailbox plan id'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->newMailboxPlan((string) $in->getArgument('email'), (int) $in->getArgument('plan'));

                return self::ok($io, sprintf('%s moved to plan %s.', $in->getArgument('email'), $in->getArgument('plan')));
            }),

            new ApiCommand('users:activate', 'Activate all services for a mailbox', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->users()->activate((string) $in->getArgument('email'));

                    return self::ok($io, sprintf('Activated %s.', $in->getArgument('email')));
                }),

            new ApiCommand('users:deactivate', 'Deactivate all services for a mailbox', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->users()->deactivate((string) $in->getArgument('email'));

                    return self::ok($io, sprintf('Deactivated %s.', $in->getArgument('email')));
                }),

            new ApiCommand('users:toggle-active', 'Toggle a mailbox active/inactive', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->users()->toggleActive((string) $in->getArgument('email'));

                    return self::ok($io, sprintf('Toggled %s.', $in->getArgument('email')));
                }),

            new ApiCommand('users:count', 'Count users, optionally filtered by domain and/or plan', [], [
                ['domain', 'value', 'Count within this domain'],
                ['plan', 'value', 'Count within this mailbox plan id'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $io->writeln((string) $c->users()->count($in->getOption('domain'), self::planId($in)));

                return Command::SUCCESS;
            }),

            new ApiCommand('users:set-spam-tolerance', 'Set spam tolerance (1 tolerant .. 5 aggressive)', [
                ['email', true, 'E-mail address'],
                ['score', true, 'Tolerance score 1-5'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->setSpamTolerance((string) $in->getArgument('email'), (int) $in->getArgument('score'));

                return self::ok($io, sprintf('Spam tolerance for %s set to %s.', $in->getArgument('email'), $in->getArgument('score')));
            }),

            new ApiCommand('users:temporary-access', 'Set a time-limited temporary password (admin only)', [['email', true, 'E-mail address']], [
                ['time-window', 'value', 'Minutes until expiry (default 10)'],
                ['password', 'value', 'Specific temporary password (otherwise generated)'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $window = $in->getOption('time-window');
                $c->users()->temporaryAccess(
                    (string) $in->getArgument('email'),
                    $window !== null ? (int) $window : null,
                    $in->getOption('password'),
                );

                return self::ok($io, sprintf('Temporary access set for %s.', $in->getArgument('email')));
            }),

            new ApiCommand('users:log-login', 'Record a login in the last-login table', [
                ['email', true, 'E-mail address'],
                ['service', true, 'imap | pop3 | webmail | smtp'],
                ['ip', true, 'Client IPv4 address'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $service = Service::tryFrom((string) $in->getArgument('service'));
                if ($service === null) {
                    $io->error('service must be one of: imap, pop3, webmail, smtp');

                    return Command::FAILURE;
                }
                $c->users()->logLogin((string) $in->getArgument('email'), $service, (string) $in->getArgument('ip'));

                return self::ok($io, 'Login logged.');
            }),

            new ApiCommand('users:set-max-mails', 'Set the daily outgoing mail limit', [
                ['email', true, 'E-mail address'],
                ['count', true, 'Mails per rolling 24h'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->setMaxMailsSentPerDay((string) $in->getArgument('email'), (int) $in->getArgument('count'));

                return self::ok($io, sprintf('Daily limit for %s set to %s.', $in->getArgument('email'), $in->getArgument('count')));
            }),

            new ApiCommand('users:reset-mails', 'Reset the outgoing mail counter', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->users()->resetMailsSentPerDay((string) $in->getArgument('email'));

                    return self::ok($io, sprintf('Reset outgoing counter for %s.', $in->getArgument('email')));
                }),

            new ApiCommand('users:detailed-last-login', 'Last 20 unique login IPs for a mailbox', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $rows = array_map(
                        static fn ($l): array => [$l->ip ?? '—', $l->service ?? '—', $l->timestamp ?? '—'],
                        $c->users()->detailedLastLogin((string) $in->getArgument('email')),
                    );

                    return self::table($io, ['IP', 'Service', 'Timestamp'], $rows, 'login(s)');
                }),

            new ApiCommand('users:latest-logins', 'All logins in the last 10 minutes', [], [['plan', 'value', 'Restrict to a mailbox plan id']],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $rows = array_map(
                        static fn ($l): array => [$l->email ?? '—', $l->ip ?? '—', $l->service ?? '—', $l->timestamp ?? '—'],
                        $c->users()->latestLogins(self::planId($in)),
                    );

                    return self::table($io, ['Email', 'IP', 'Service', 'Timestamp'], $rows, 'login(s)');
                }),

            new ApiCommand('users:inactive-since', 'List users with no login since a date', [['date', true, 'Date YYYY-MM-DD, e.g. "2025-02-21"']], [
                ['plan', 'value', 'Restrict to a mailbox plan id'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $rows = array_map(
                    static fn ($l): array => [$l->email ?? '—', $l->timestamp ?? '—'],
                    $c->users()->listWithLastLoginBefore((string) $in->getArgument('date'), self::planId($in)),
                );

                return self::table($io, ['Email', 'Last login'], $rows, 'user(s)');
            }),

            new ApiCommand('users:list-snapshots', 'List backup snapshots for a mailbox', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $rows = array_map(
                        static fn ($s): array => [$s->serial, $s->timestamp ?? '—', $s->size ?? '—'],
                        $c->users()->listSnapshots((string) $in->getArgument('email')),
                    );

                    return self::table($io, ['Serial', 'Timestamp', 'Size'], $rows, 'snapshot(s)');
                }),

            new ApiCommand('users:restore-snapshot', 'Queue a snapshot restore', [
                ['email', true, 'E-mail address'],
                ['serial', true, 'Snapshot serial (from list-snapshots)'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->restoreSnapshot((string) $in->getArgument('email'), (string) $in->getArgument('serial'));

                return self::ok($io, 'Restore job queued.');
            }),

            new ApiCommand('users:list-restore-jobs', 'List recent restore jobs for a mailbox', [['email', true, 'E-mail address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $rows = array_map(
                        static fn ($j): array => [$j->snapshotDate ?? '—', $j->status, $j->dateQueued ?? '—', $j->dateFinished ?? '—', (string) $j->mailsRestored, (string) $j->mailsIgnored],
                        $c->users()->listRestoreJobs((string) $in->getArgument('email')),
                    );

                    return self::table($io, ['Snapshot', 'Status', 'Queued', 'Finished', 'Restored', 'Ignored'], $rows, 'job(s)');
                }),

            new ApiCommand('users:list-flags', 'List all set flags and their counts', [], [['plan', 'value', 'Restrict to a mailbox plan id']],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $rows = array_map(
                        static fn ($f): array => [$f->flag, (string) $f->count],
                        $c->users()->listFlags(self::planId($in)),
                    );

                    return self::table($io, ['Flag', 'Count'], $rows, 'flag(s)');
                }),

            new ApiCommand('users:list-flagged', 'List mailboxes carrying a flag', [['flag', true, 'Flag name']], [
                ['plan', 'value', 'Restrict to a mailbox plan id'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $rows = array_map(
                    static fn ($m): array => [$m->email, $m->dateSet ?? '—'],
                    $c->users()->listFlagged((string) $in->getArgument('flag'), self::planId($in)),
                );

                return self::table($io, ['Email', 'Set at'], $rows, 'mailbox(es)');
            }),

            new ApiCommand('users:set-flag', 'Set a flag on a mailbox', [
                ['email', true, 'E-mail address'],
                ['flag', true, 'Flag name'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->setFlag((string) $in->getArgument('email'), (string) $in->getArgument('flag'));

                return self::ok($io, sprintf('Set flag "%s" on %s.', $in->getArgument('flag'), $in->getArgument('email')));
            }),

            new ApiCommand('users:unflag', 'Remove a flag from a mailbox', [
                ['email', true, 'E-mail address'],
                ['flag', true, 'Flag name'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->users()->unflag((string) $in->getArgument('email'), (string) $in->getArgument('flag'));

                return self::ok($io, sprintf('Removed flag "%s" from %s.', $in->getArgument('flag'), $in->getArgument('email')));
            }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function mailboxplans(): array
    {
        return [
            new ApiCommand('mailboxplans:list', 'List all mailbox plans', [], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $rows = array_map(
                        static fn ($p): array => [(string) $p->id, $p->name, (string) $p->mailboxQuota, self::yn($p->imap), self::yn($p->pop3), self::yn($p->smtp), self::yn($p->webmail), $p->dateCreated ?? '—'],
                        $c->mailboxplans()->list(),
                    );

                    return self::table($io, ['ID', 'Name', 'Quota', 'IMAP', 'POP3', 'SMTP', 'Webmail', 'Created'], $rows, 'plan(s)');
                }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function mailfilter(): array
    {
        return [
            new ApiCommand('mailfilter:smtp-limit-hits', 'Accounts that recently hit the SMTP limit', [], [['plan', 'value', 'Restrict to a mailbox plan id']],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $rows = array_map(
                        static fn ($h): array => [$h->email, $h->ip ?? '—', $h->lastHit ?? '—'],
                        $c->mailfilter()->latestSmtpLimitHits(self::planId($in)),
                    );

                    return self::table($io, ['Email', 'IP', 'Last hit'], $rows, 'hit(s)');
                }),

            new ApiCommand('mailfilter:spam-flags', 'Accounts recently flagged', [], [['plan', 'value', 'Restrict to a mailbox plan id']],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $rows = array_map(
                        static fn ($f): array => [$f->email, $f->flag, $f->dateSet ?? '—'],
                        $c->mailfilter()->latestSpamFlags(self::planId($in)),
                    );

                    return self::table($io, ['Email', 'Flag', 'Set at'], $rows, 'flag(s)');
                }),

            new ApiCommand('mailfilter:list-whitelist', 'List whitelist entries for a recipient', [['recipient', true, 'Recipient address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    return self::strings($io, $c->mailfilter()->listWhitelist((string) $in->getArgument('recipient')), 'entry/entries');
                }),

            new ApiCommand('mailfilter:list-blacklist', 'List blacklist entries for a recipient', [['recipient', true, 'Recipient address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    return self::strings($io, $c->mailfilter()->listBlacklist((string) $in->getArgument('recipient')), 'entry/entries');
                }),

            new ApiCommand('mailfilter:whitelist', 'Whitelist a sender for a recipient', [
                ['recipient', true, 'Recipient address'],
                ['sender', true, 'Sender address'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->mailfilter()->whitelistSender((string) $in->getArgument('recipient'), (string) $in->getArgument('sender'));

                return self::ok($io, 'Whitelist entry added.');
            }),

            new ApiCommand('mailfilter:blacklist', 'Blacklist a sender for a recipient', [
                ['recipient', true, 'Recipient address'],
                ['sender', true, 'Sender address'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->mailfilter()->blacklistSender((string) $in->getArgument('recipient'), (string) $in->getArgument('sender'));

                return self::ok($io, 'Blacklist entry added.');
            }),

            new ApiCommand('mailfilter:whitedelist', 'Remove a sender from a whitelist', [
                ['recipient', true, 'Recipient address'],
                ['sender', true, 'Sender address'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->mailfilter()->whitedelistSender((string) $in->getArgument('recipient'), (string) $in->getArgument('sender'));

                return self::ok($io, 'Whitelist entry removed.');
            }),

            new ApiCommand('mailfilter:blackdelist', 'Remove a sender from a blacklist', [
                ['recipient', true, 'Recipient address'],
                ['sender', true, 'Sender address'],
            ], [], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $c->mailfilter()->blackdelistSender((string) $in->getArgument('recipient'), (string) $in->getArgument('sender'));

                return self::ok($io, 'Blacklist entry removed.');
            }),

            new ApiCommand('mailfilter:clear-whitelist', 'Clear a recipient whitelist', [['recipient', true, 'Recipient address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->mailfilter()->clearWhitelist((string) $in->getArgument('recipient'));

                    return self::ok($io, 'Whitelist cleared.');
                }),

            new ApiCommand('mailfilter:clear-blacklist', 'Clear a recipient blacklist', [['recipient', true, 'Recipient address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $c->mailfilter()->clearBlacklist((string) $in->getArgument('recipient'));

                    return self::ok($io, 'Blacklist cleared.');
                }),

            new ApiCommand('mailfilter:rbl-lookup', 'Look up an IPv4 address against the RBLs', [['ip', true, 'IPv4 address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $ip = (string) $in->getArgument('ip');
                    $listed = $c->mailfilter()->isListedOnRbl($ip);
                    $io->writeln($listed
                        ? sprintf('<comment>%s is LISTED on one or more RBLs.</comment>', $ip)
                        : sprintf('<info>%s is clean.</info>', $ip));

                    return $listed ? Command::FAILURE : Command::SUCCESS;
                }),

            new ApiCommand('mailfilter:cdl-lookup', 'Look up an IPv4 address against the CDL', [['ip', true, 'IPv4 address']], [],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $ip = (string) $in->getArgument('ip');
                    $listed = $c->mailfilter()->isListedOnCdl($ip);
                    $io->writeln($listed
                        ? sprintf('<comment>%s is LISTED on the CDL.</comment>', $ip)
                        : sprintf('<info>%s is clean.</info>', $ip));

                    return $listed ? Command::FAILURE : Command::SUCCESS;
                }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function reports(): array
    {
        return [
            new ApiCommand('reports:suspicious-activity', 'Mailboxes with suspicious login patterns', [], [['plan', 'value', 'Restrict to a mailbox plan id']],
                static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                    $r = $c->reports()->suspiciousMailboxActivity(self::planId($in));
                    $io->writeln(sprintf(
                        '<comment>Scanned %s · %d days · min ASNs %d · skip [%s]</comment>',
                        $r->scannedAt ?? '—',
                        $r->days,
                        $r->minAsns,
                        implode(', ', $r->skipFlags),
                    ));
                    $rows = array_map(
                        static fn ($h): array => [$h->email, (string) $h->nAsn, (string) $h->nCountries, (string) $h->nIps, implode(' ', $h->countries)],
                        $r->hits,
                    );

                    return self::table($io, ['Email', '#ASN', '#Countries', '#IPs', 'Countries'], $rows, 'suspicious mailbox(es)');
                }),
        ];
    }

    /** @return list<ApiCommand> */
    private static function datadump(): array
    {
        return [
            new ApiCommand('datadump:fetch', 'Fetch the latest (PGP/gzip) data dump', [], [
                ['output', 'value', 'Write to this file instead of stdout'],
            ], static function (InputInterface $in, SymfonyStyle $io, MailcoreClient $c): int {
                $data = $c->datadump()->fetchLatest();
                $output = $in->getOption('output');
                if ($output !== null) {
                    file_put_contents((string) $output, $data);

                    return self::ok($io, sprintf('Wrote %d bytes to %s.', strlen($data), $output));
                }
                fwrite(STDOUT, $data);

                return Command::SUCCESS;
            }),
        ];
    }

    // --- shared helpers -----------------------------------------------------

    private static function planId(InputInterface $in): ?int
    {
        $plan = $in->getOption('plan');

        return $plan !== null ? (int) $plan : null;
    }

    /** Resolve a password from --password or a hidden prompt. */
    private static function password(InputInterface $in, SymfonyStyle $io, string $prompt): string
    {
        $password = $in->getOption('password');

        return $password !== null ? (string) $password : (string) $io->askHidden($prompt);
    }

    private static function yn(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    /** Turn a snake_case API field name into a readable label. */
    private static function humanize(string $key): string
    {
        return ucfirst(str_replace('_', ' ', $key));
    }

    /** Render a raw API value for a definition list (arrays joined, empties dashed). */
    private static function display(mixed $value): string
    {
        if (is_array($value)) {
            return $value === [] ? '—' : implode(', ', array_map(static fn ($v): string => (string) $v, $value));
        }

        return ($value === null || $value === '') ? '—' : (string) $value;
    }

    /**
     * @param list<string> $items
     */
    private static function strings(SymfonyStyle $io, array $items, string $noun): int
    {
        if ($items === []) {
            $io->warning(sprintf('No %s.', $noun));

            return Command::SUCCESS;
        }
        $io->listing($items);
        $io->writeln(sprintf('<info>%d %s.</info>', count($items), $noun));

        return Command::SUCCESS;
    }

    /**
     * @param list<string>            $headers
     * @param list<array<int, string>> $rows
     */
    private static function table(SymfonyStyle $io, array $headers, array $rows, string $noun): int
    {
        if ($rows === []) {
            $io->warning(sprintf('No %s.', $noun));

            return Command::SUCCESS;
        }
        $io->table($headers, $rows);
        $io->writeln(sprintf('<info>%d %s.</info>', count($rows), $noun));

        return Command::SUCCESS;
    }

    private static function ok(SymfonyStyle $io, string $message): int
    {
        $io->success($message);

        return Command::SUCCESS;
    }
}
