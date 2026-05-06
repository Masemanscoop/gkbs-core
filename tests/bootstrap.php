<?php
/**
 * Unit-test bootstrap for gkbs-core.
 *
 * Loads Composer autoload + Brain Monkey for stubbing WordPress functions.
 * The actual WordPress core is NOT loaded — wpdb interactions are mocked
 * per test via the wpdb-stub helper or PHPUnit createMock().
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (! defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/wordpress/');
}

if (! class_exists('wpdb')) {
    /**
     * Minimal wpdb stub. Tests can extend it or replace via Mockery.
     * @phpstan-ignore-next-line
     */
    class wpdb // phpcs:ignore
    {
        public string $last_error = '';

        public array $inserted = [];

        public array $queries = [];

        public function insert(string $table, array $data, $format = null): int|false
        {
            $this->inserted[] = ['table' => $table, 'data' => $data, 'format' => $format];
            return 1;
        }

        public function prepare(string $query, ...$args): string
        {
            return vsprintf(str_replace(['%s', '%d'], ["'%s'", '%d'], $query), $args);
        }

        public function query(string $sql): int|false
        {
            $this->queries[] = $sql;
            return 0;
        }
    }
}
