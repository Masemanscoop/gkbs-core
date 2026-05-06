<?php
/**
 * gkbs-core Plugin runtime.
 *
 * One instance per WP request (singleton via Loader::plugin()).
 * Holds the service container and module loader. Distributions
 * register their own ServiceProviders into this instance.
 */

declare(strict_types=1);

namespace GKBS\Core;

use GKBS\Core\Container\Container;
use GKBS\Core\Module\ModuleLoader;

final class Plugin
{
    private Container $container;
    private ModuleLoader $modules;
    private bool $booted = false;

    public function __construct()
    {
        $this->container = new Container();
        $this->modules   = new ModuleLoader($this->container);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function modules(): ModuleLoader
    {
        return $this->modules;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->modules->bootAll();
        $this->booted = true;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }
}
