<?php

declare(strict_types=1);

namespace Yousef\LaraOrcest\Console;

use Symfony\Component\Console\Application;
use Yousef\LaraOrcest\Console\Commands\ApproveCommand;
use Yousef\LaraOrcest\Console\Commands\BuildCommand;
use Yousef\LaraOrcest\Console\Commands\InitCommand;
use Yousef\LaraOrcest\Console\Commands\StatusCommand;

class LaraOrcestApplication extends Application
{
    public const NAME = 'LaraOrcest Standalone Control Plane';
    public const VERSION = '1.0.0';

    public function __construct()
    {
        parent::__construct(self::NAME, self::VERSION);

        $this->setAutoExit(false);

        $this->addCommands([
            new InitCommand(),
            new BuildCommand(),
            new StatusCommand(),
            new ApproveCommand(),
        ]);
    }
}
