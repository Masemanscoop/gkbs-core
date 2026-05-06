<?php

declare(strict_types=1);

namespace GKBS\Core\Audit;

/**
 * Contract for recording audit events.
 *
 * Subscribers depend on this rather than {@see AuditLogger} directly so
 * tests can substitute spies / in-memory implementations.
 */
interface RecordsAudit
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function record(
        string $entityType,
        string $entityId,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $userId = null
    ): void;
}
