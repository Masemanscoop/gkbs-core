<?php

declare(strict_types=1);

namespace GKBS\Core\Audit;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Persistent audit log writer — every legally relevant change goes through
 * this service. The DB table is provided by the plugin distribution that
 * embeds this core (mb_suite ships wp_mbs_audit_log via V001).
 *
 * Records are append-only and retained for 3 years (UWG §5 / DSGVO Art. 5).
 * Cleanup happens via {@see AuditLogger::purgeOlderThan()} from the plugin
 * cron handler.
 */
final class AuditLogger implements RecordsAudit
{
    public function __construct(
        private \wpdb $wpdb,
        private string $table,
        private ?LoggerInterface $logger = null
    ) {
    }

    public function record(
        string $entityType,
        string $entityId,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $userId = null
    ): void {
        $userId   = $userId ?? (function_exists('get_current_user_id') ? get_current_user_id() : 0);
        $userId   = $userId > 0 ? $userId : null;
        $userRole = $this->resolveUserRole($userId);

        $row = [
            'entity_type'  => substr($entityType, 0, 40),
            'entity_id'    => substr($entityId, 0, 64),
            'action'       => substr($action, 0, 20),
            'before_value' => $before !== null ? $this->encode($before) : null,
            'after_value'  => $after !== null ? $this->encode($after) : null,
            'user_id'      => $userId,
            'user_role'    => $userRole,
            'ip'           => $this->resolveIp(),
            'user_agent'   => $this->resolveUserAgent(),
            'created_at'   => function_exists('current_time')
                ? current_time('mysql', true)
                : gmdate('Y-m-d H:i:s'),
        ];

        $formats = ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s'];

        $inserted = $this->wpdb->insert($this->table, $row, $formats);

        if ($inserted === false) {
            $this->logger?->error('audit.insert_failed', [
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'action'      => $action,
                'wpdb_error'  => $this->wpdb->last_error,
            ]);
            return;
        }

        $this->logger?->debug('audit.recorded', [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'user_id'     => $userId,
        ]);
    }

    /**
     * Removes audit rows older than $years years. Returns deleted row count.
     * Use from the plugin's daily cron — the 3-year retention enforces
     * DSGVO storage-limitation while preserving legal evidence.
     */
    public function purgeOlderThan(int $years): int
    {
        if ($years < 1) {
            return 0;
        }

        $threshold = (new DateTimeImmutable("-{$years} years"))->format('Y-m-d H:i:s');

        $sql = $this->wpdb->prepare(
            'DELETE FROM `' . $this->table . '` WHERE created_at < %s',
            $threshold
        );

        $deleted = (int) $this->wpdb->query($sql);

        if ($deleted > 0) {
            $this->logger?->info('audit.purged', [
                'years' => $years,
                'rows'  => $deleted,
            ]);
        }

        return $deleted;
    }

    private function encode(array $payload): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($payload, $flags)
            : json_encode($payload, $flags);

        return $encoded === false ? '{}' : $encoded;
    }

    private function resolveUserRole(?int $userId): ?string
    {
        if ($userId === null || $userId <= 0 || ! function_exists('get_userdata')) {
            return null;
        }

        $user = get_userdata($userId);
        if ($user === false || empty($user->roles)) {
            return null;
        }

        return substr((string) $user->roles[0], 0, 40);
    }

    private function resolveIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip === null || ! is_string($ip)) {
            return null;
        }

        $valid = filter_var($ip, FILTER_VALIDATE_IP);
        return $valid === false ? null : (string) $valid;
    }

    private function resolveUserAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ua === null || ! is_string($ua)) {
            return null;
        }

        $clean = function_exists('sanitize_text_field')
            ? sanitize_text_field($ua)
            : strip_tags($ua);

        return mb_substr($clean, 0, 255);
    }
}
