<?php
/**
 * Redacts sensitive context fields before they reach any sink.
 * Field names with these substrings are masked: api_key, password,
 * secret, token, authorization. Email addresses and phone-like
 * sequences in message + context strings are masked too.
 */

declare(strict_types=1);

namespace GKBS\Core\Logger;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class SensitiveDataRedactor implements ProcessorInterface
{
    private const KEY_PATTERNS = [
        'api_key', 'apikey', 'password', 'secret', 'token', 'authorization', 'x-api-key',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->redactArray($record->context);
        return $record->with(
            message: $this->redactString($record->message),
            context: $context
        );
    }

    private function redactArray(array $context): array
    {
        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            foreach (self::KEY_PATTERNS as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $context[$key] = '***REDACTED***';
                    continue 2;
                }
            }
            if (is_array($value)) {
                $context[$key] = $this->redactArray($value);
            } elseif (is_string($value)) {
                $context[$key] = $this->redactString($value);
            }
        }
        return $context;
    }

    private function redactString(string $value): string
    {
        $value = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '***EMAIL***', $value) ?? $value;
        $value = preg_replace('/(?<!\d)(\+?\d[\d \-\/]{8,}\d)(?!\d)/', '***PHONE***', $value) ?? $value;
        return $value;
    }
}
