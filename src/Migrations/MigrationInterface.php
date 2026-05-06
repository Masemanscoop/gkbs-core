<?php
/**
 * A single migration. Identified by version (e.g. "V001").
 * up() must be idempotent: re-running on the same DB must be safe.
 */

declare(strict_types=1);

namespace GKBS\Core\Migrations;

interface MigrationInterface
{
    public function version(): string;

    public function description(): string;

    public function up(\wpdb $wpdb): void;
}
