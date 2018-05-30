<?php

namespace Icinga\Module\Director\Clicommands;

use Icinga\Module\Director\Cli\Command;
use Icinga\Module\Director\MainRunner;

/**
 * Sync a vCenter or ESXi host
 */
class DaemonCommand extends Command
{
    /**
     * Sync all objects
     *
     * Still a prototype
     *
     * USAGE
     *
     * icingacli director daemon run [--db-resource <name>]
     */
    public function runAction()
    {
        $this->app->getModuleManager()->loadEnabledModules();
        $runner = new MainRunner();
        $runner->showTrace($this->showTrace());
        $runner->run();
    }
}
