<?php
/**
 * Logger factory: builds a PSR-3 LoggerInterface backed by Monolog.
 *
 * Default sinks: rotating file in wp-content/uploads/<channel>-logs/
 * plus optional DB-sink via DbHandler (added in Phase 1 when wpdb
 * tables exist).
 *
 * Sensitive data (api_key, email, phone, password) is redacted via
 * SensitiveDataRedactor before any sink writes.
 */

declare(strict_types=1);

namespace GKBS\Core\Logger;

use Monolog\Handler\NullHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\WebProcessor;
use Psr\Log\LoggerInterface;

final class LoggerFactory
{
    public static function create(string $channel, ?string $logDir = null, int $level = Logger::DEBUG): LoggerInterface
    {
        $logger = new Logger($channel);

        if ($logDir !== null && is_dir($logDir) && is_writable($logDir)) {
            $logger->pushHandler(new RotatingFileHandler(
                $logDir . '/' . $channel . '.log',
                14,
                $level
            ));
        } else {
            $logger->pushHandler(new NullHandler());
        }

        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushProcessor(new WebProcessor());
        $logger->pushProcessor(new SensitiveDataRedactor());

        return $logger;
    }
}
