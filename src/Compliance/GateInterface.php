<?php
/**
 * Compliance-Gate. Each gate examines plugin state and reports
 * green/yellow/red. Red gates may block cutover actions.
 */

declare(strict_types=1);

namespace GKBS\Core\Compliance;

use GKBS\Core\Health\HealthStatus;

interface GateInterface
{
    public function id(): string;

    public function check(): HealthStatus;
}
