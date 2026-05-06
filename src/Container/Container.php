<?php
/**
 * PSR-11 service container, league/container under the hood.
 *
 * Distributions and modules call $container->add() / $container->get()
 * / $container->bind(). Auto-wiring via ReflectionContainer is enabled
 * by default.
 */

declare(strict_types=1);

namespace GKBS\Core\Container;

use League\Container\Container as LeagueContainer;
use League\Container\ReflectionContainer;
use Psr\Container\ContainerInterface;

final class Container implements ContainerInterface
{
    private LeagueContainer $inner;

    public function __construct()
    {
        $this->inner = new LeagueContainer();
        $this->inner->delegate(new ReflectionContainer(true));
    }

    public function add(string $id, $concrete = null): self
    {
        $this->inner->add($id, $concrete);
        return $this;
    }

    public function addShared(string $id, $concrete = null): self
    {
        $this->inner->addShared($id, $concrete);
        return $this;
    }

    public function get(string $id)
    {
        return $this->inner->get($id);
    }

    public function has(string $id): bool
    {
        return $this->inner->has($id);
    }

    public function inner(): LeagueContainer
    {
        return $this->inner;
    }
}
