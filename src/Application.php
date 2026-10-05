<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli;

use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication
{
    /** Kept in step with the release tag; tools/check-versions.php enforces it. */
    public const VERSION = '0.1.8';

    public function __construct()
    {
        parent::__construct('Mailcore CLI', self::VERSION);

        $this->addCommands(Commands::all());
    }
}
