<?php
/**
 * Base ServiceProvider for plugin modules.
 *
 * Each module (Tarif, Quote, Order, Industry, ...) extends this and
 * registers its services into the container. boot() runs after all
 * providers are registered, so cross-module wiring is safe there.
 */

declare(strict_types=1);

namespace GKBS\Core\Module;

use GKBS\Core\Container\Container;

abstract class ServiceProvider
{
    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    abstract public function register(): void;

    public function boot(): void
    {
        // Override in subclass when cross-module wiring is needed.
    }
}
