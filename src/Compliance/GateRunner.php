<?php
/**
 * Runs all registered compliance gates and aggregates results.
 * Distributions register gates; Settings-Page renders the summary.
 */

declare(strict_types=1);

namespace GKBS\Core\Compliance;

use GKBS\Core\Health\HealthStatus;

final class GateRunner
{
    /** @var GateInterface[] */
    private array $gates = [];

    public function add(GateInterface $gate): self
    {
        $this->gates[$gate->id()] = $gate;
        return $this;
    }

    /**
     * @return array<string, HealthStatus>
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->gates as $id => $gate) {
            try {
                $results[$id] = $gate->check();
            } catch (\Throwable $e) {
                $results[$id] = HealthStatus::red('Gate threw: ' . $e->getMessage());
            }
        }
        return $results;
    }

    public function hasRedGates(): bool
    {
        foreach ($this->runAll() as $status) {
            if ($status->level === HealthStatus::RED) {
                return true;
            }
        }
        return false;
    }
}
