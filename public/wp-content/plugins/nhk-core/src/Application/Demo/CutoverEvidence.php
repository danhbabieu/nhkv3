<?php
declare(strict_types=1);

namespace NHK\Core\Application\Demo;

final class CutoverEvidence
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function redact(array $payload): array
    {
        $secretKeys = ['authorization', 'cookie', 'password', 'private_key', 'secret', 'token'];
        $result = [];
        foreach ($payload as $key => $value) {
            $normalized = strtolower((string) $key);
            $result[$key] = in_array($normalized, $secretKeys, true) || str_contains($normalized, 'header')
                ? '[REDACTED]' : (is_array($value) ? self::redact($value) : $value);
        }
        return $result;
    }
}
