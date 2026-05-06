<?php
/**
 * Lightweight health-check for plugin status pages.
 * Modules push checks via add(); Settings-Page renders results.
 */

declare(strict_types=1);

namespace GKBS\Core\Health;

final class HealthCheck
{
    /** @var array<string, callable():HealthStatus> */
    private array $checks = [];

    public function add(string $id, callable $check): self
    {
        $this->checks[$id] = $check;
        return $this;
    }

    /**
     * @return array<string, HealthStatus>
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->checks as $id => $check) {
            try {
                $results[$id] = $check();
            } catch (\Throwable $e) {
                $results[$id] = HealthStatus::red('Check threw: ' . $e->getMessage());
            }
        }
        return $results;
    }
}
