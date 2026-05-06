<?php
/**
 * Module loader.
 *
 * Distributions add their ServiceProviders here. register() runs
 * immediately, boot() runs after all are registered.
 */

declare(strict_types=1);

namespace GKBS\Core\Module;

use GKBS\Core\Container\Container;

final class ModuleLoader
{
    private Container $container;
    /** @var ServiceProvider[] */
    private array $providers = [];
    private bool $booted = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function add(ServiceProvider $provider): self
    {
        $provider->register();
        $this->providers[] = $provider;
        return $this;
    }

    public function bootAll(): void
    {
        if ($this->booted) {
            return;
        }
        foreach ($this->providers as $provider) {
            $provider->boot();
        }
        $this->booted = true;
    }
}
