<?php

declare(strict_types=1);

namespace Inboxcom\Mailcore\Cli;

use Symfony\Component\Console\Application as BaseApplication;

final class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('Mailcore CLI', '0.1.0');

        $this->addCommands(Commands::all());
    }
}
