<?php

declare(strict_types=1);

namespace GKBS\Core\Health;

final class HealthStatus
{
    public const GREEN  = 'green';
    public const YELLOW = 'yellow';
    public const RED    = 'red';

    private function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly array $context = []
    ) {
    }

    public static function green(string $message, array $context = []): self
    {
        return new self(self::GREEN, $message, $context);
    }

    public static function yellow(string $message, array $context = []): self
    {
        return new self(self::YELLOW, $message, $context);
    }

    public static function red(string $message, array $context = []): self
    {
        return new self(self::RED, $message, $context);
    }
}
