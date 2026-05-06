<?php
/**
 * Migrations-Runner.
 *
 * Tracks applied migrations in the option specified by $optionKey.
 * Skips already-applied versions, runs pending ones in order.
 * Distributions construct one Runner per plugin and register
 * their MigrationInterface implementations.
 */

declare(strict_types=1);

namespace GKBS\Core\Migrations;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class Runner
{
    private \wpdb $wpdb;
    private string $optionKey;
    private LoggerInterface $logger;
    /** @var MigrationInterface[] */
    private array $migrations = [];

    public function __construct(\wpdb $wpdb, string $optionKey, ?LoggerInterface $logger = null)
    {
        $this->wpdb      = $wpdb;
        $this->optionKey = $optionKey;
        $this->logger    = $logger ?? new NullLogger();
    }

    public function add(MigrationInterface $migration): self
    {
        $this->migrations[] = $migration;
        return $this;
    }

    /**
     * @return string[] Versions that were applied during this run.
     */
    public function run(): array
    {
        $applied = $this->appliedVersions();
        $newlyApplied = [];

        foreach ($this->migrations as $migration) {
            $version = $migration->version();
            if (in_array($version, $applied, true)) {
                continue;
            }

            try {
                $migration->up($this->wpdb);
                $applied[] = $version;
                $newlyApplied[] = $version;
                $this->logger->info('Migration applied', [
                    'version' => $version,
                    'description' => $migration->description(),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('Migration failed', [
                    'version' => $version,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        if ($newlyApplied !== []) {
            $this->saveAppliedVersions($applied);
        }

        return $newlyApplied;
    }

    /**
     * @return string[]
     */
    public function appliedVersions(): array
    {
        $stored = get_option($this->optionKey, []);
        return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
    }

    /**
     * @param string[] $versions
     */
    private function saveAppliedVersions(array $versions): void
    {
        update_option($this->optionKey, array_values(array_unique($versions)), false);
    }
}
