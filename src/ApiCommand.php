<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli;

use Inboxcom\Mailcore\Exception\MailcoreException;
use Inboxcom\Mailcore\MailcoreClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A single CLI command described declaratively. Every Mailcore command is one
 * of these, defined in {@see Commands}: a name, arguments/options, and a
 * handler closure that receives the input, a styled output, and a ready-built
 * client. The client is created lazily per run and all SDK/credential errors
 * are turned into a clean one-line error instead of a stack trace.
 */
final class ApiCommand extends Command
{
    /** @var \Closure(InputInterface, SymfonyStyle, MailcoreClient): int */
    private readonly \Closure $handler;

    /** @var \Closure(): MailcoreClient */
    private \Closure $clientProvider;

    /**
     * @param array<int, array{0: string, 1: bool, 2: string}>            $arguments [name, required, description]
     * @param array<int, array{0: string, 1: 'value'|'none', 2: string}> $options   [name, kind, description]
     * @param \Closure(InputInterface, SymfonyStyle, MailcoreClient): int $handler
     */
    public function __construct(
        string $name,
        string $description,
        array $arguments,
        array $options,
        \Closure $handler,
    ) {
        parent::__construct($name);
        $this->setDescription($description);
        $this->handler = $handler;
        // Default resolves credentials from env/config file; tests inject their own provider.
        $this->clientProvider = static fn (): MailcoreClient => ClientFactory::create();

        foreach ($arguments as [$argName, $required, $argDescription]) {
            $this->addArgument(
                $argName,
                $required ? InputArgument::REQUIRED : InputArgument::OPTIONAL,
                $argDescription,
            );
        }

        foreach ($options as [$optName, $kind, $optDescription]) {
            $this->addOption(
                $optName,
                null,
                $kind === 'none' ? InputOption::VALUE_NONE : InputOption::VALUE_REQUIRED,
                $optDescription,
            );
        }
    }

    /** Override how the client is built (used by tests to inject a mock transport). */
    public function setClientProvider(\Closure $clientProvider): void
    {
        $this->clientProvider = $clientProvider;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            return ($this->handler)($input, $io, ($this->clientProvider)());
        } catch (MailcoreException | \RuntimeException | \InvalidArgumentException $e) {
            // API errors, missing credentials, and bad argument values all render
            // as a clean one-line error rather than a stack trace.
            $io->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
