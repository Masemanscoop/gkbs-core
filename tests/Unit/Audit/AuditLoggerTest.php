<?php

declare(strict_types=1);

namespace GKBS\Core\Tests\Unit\Audit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use GKBS\Core\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use wpdb;

final class AuditLoggerTest extends TestCase
{
    private wpdb $wpdb;
    private LoggerInterface $logger;
    private AuditLogger $audit;

    protected function setUp(): void
    {
        Monkey\setUp();

        Functions\stubs([
            'current_time'        => static fn () => '2026-05-06 19:30:00',
            'get_current_user_id' => static fn () => 0,
            'get_userdata'        => static fn () => false,
            'wp_json_encode'      => static fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sanitize_text_field' => static fn ($s) => trim(strip_tags((string) $s)),
        ]);

        $this->wpdb   = new wpdb();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->audit  = new AuditLogger($this->wpdb, 'wp_mbs_audit_log', $this->logger);

        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_record_inserts_normalised_row(): void
    {
        $this->logger
            ->expects(self::once())
            ->method('debug')
            ->with('audit.recorded', self::callback(static fn (array $ctx) =>
                $ctx['entity_type'] === 'tariff' &&
                $ctx['entity_id'] === '42' &&
                $ctx['action'] === 'updated'
            ));

        $this->audit->record(
            'tariff',
            '42',
            'updated',
            ['price_net' => 19.99],
            ['price_net' => 24.99]
        );

        self::assertCount(1, $this->wpdb->inserted);

        $row = $this->wpdb->inserted[0]['data'];
        self::assertSame('wp_mbs_audit_log', $this->wpdb->inserted[0]['table']);
        self::assertSame('tariff', $row['entity_type']);
        self::assertSame('42', $row['entity_id']);
        self::assertSame('updated', $row['action']);
        self::assertSame('{"price_net":19.99}', $row['before_value']);
        self::assertSame('{"price_net":24.99}', $row['after_value']);
        self::assertNull($row['user_id']);
        self::assertNull($row['user_role']);
        self::assertSame('2026-05-06 19:30:00', $row['created_at']);
    }

    public function test_record_truncates_oversize_strings(): void
    {
        $this->audit->record(
            str_repeat('a', 100),
            str_repeat('b', 200),
            str_repeat('c', 50)
        );

        $row = $this->wpdb->inserted[0]['data'];
        self::assertSame(40, strlen($row['entity_type']));
        self::assertSame(64, strlen($row['entity_id']));
        self::assertSame(20, strlen($row['action']));
    }

    public function test_record_resolves_ip_and_user_agent(): void
    {
        $_SERVER['REMOTE_ADDR']     = '203.0.113.7';
        $_SERVER['HTTP_USER_AGENT'] = 'TestAgent/1.0 ' . str_repeat('x', 300);

        $this->audit->record('quote', 'q_abc', 'created');

        $row = $this->wpdb->inserted[0]['data'];
        self::assertSame('203.0.113.7', $row['ip']);
        self::assertSame(255, strlen($row['user_agent']));
        self::assertStringStartsWith('TestAgent/1.0', $row['user_agent']);
    }

    public function test_record_rejects_invalid_ip(): void
    {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';

        $this->audit->record('quote', 'q_abc', 'created');

        self::assertNull($this->wpdb->inserted[0]['data']['ip']);
    }

    public function test_record_logs_error_when_insert_fails(): void
    {
        $failingWpdb              = new class () extends wpdb {
            public string $last_error = 'Duplicate entry';
            public function insert(string $table, array $data, $format = null): int|false
            {
                return false;
            }
        };
        $logger                   = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('audit.insert_failed', self::callback(static fn (array $ctx) =>
                $ctx['wpdb_error'] === 'Duplicate entry'
            ));

        $audit = new AuditLogger($failingWpdb, 'wp_mbs_audit_log', $logger);
        $audit->record('tariff', '42', 'updated');
    }

    public function test_purge_older_than_returns_deleted_count(): void
    {
        $deletingWpdb = new class () extends wpdb {
            public function query(string $sql): int|false
            {
                $this->queries[] = $sql;
                return 17;
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')
            ->with('audit.purged', self::callback(static fn (array $ctx) =>
                $ctx['years'] === 3 && $ctx['rows'] === 17
            ));

        $audit   = new AuditLogger($deletingWpdb, 'wp_mbs_audit_log', $logger);
        $deleted = $audit->purgeOlderThan(3);

        self::assertSame(17, $deleted);
        self::assertCount(1, $deletingWpdb->queries);
        self::assertStringContainsString('DELETE FROM `wp_mbs_audit_log`', $deletingWpdb->queries[0]);
    }

    public function test_purge_older_than_zero_years_is_noop(): void
    {
        self::assertSame(0, $this->audit->purgeOlderThan(0));
        self::assertSame([], $this->wpdb->queries);
    }
}
