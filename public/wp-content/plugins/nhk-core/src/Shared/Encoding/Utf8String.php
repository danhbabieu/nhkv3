<?php
declare(strict_types=1);

namespace NHK\Core\Shared\Encoding;

/**
 * Strict Unicode string operations for producer code.
 *
 * PHP's native string offsets and substr() operate on bytes.  This boundary
 * keeps character-counted operations Unicode-safe even when mbstring is not
 * installed, and deliberately fails closed for malformed input.
 */
final class Utf8String
{
    public static function length(string $value, string $producer = 'utf8.string', string $path = 'value'): int
    {
        self::assertValid($value, $producer, $path);
        if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
        return count(self::characters($value, $producer, $path));
    }

    public static function slice(string $value, int $start = 0, ?int $length = null, string $producer = 'utf8.string', string $path = 'value'): string
    {
        self::assertValid($value, $producer, $path);
        if (function_exists('mb_substr')) {
            $result = mb_substr($value, $start, $length, 'UTF-8');
            if ($result !== false) return $result;
        }

        $characters = self::characters($value, $producer, $path);
        $count = count($characters);
        $offset = $start < 0 ? max(0, $count + $start) : min($start, $count);
        if ($length === null) return implode('', array_slice($characters, $offset));
        if ($length <= 0) return '';
        return implode('', array_slice($characters, $offset, $length));
    }

    public static function truncate(string $value, int $limit, string $producer = 'utf8.string', string $path = 'value'): string
    {
        return $limit <= 0 ? '' : self::slice($value, 0, $limit, $producer, $path);
    }

    public static function trim(string $value, string $characters = " \t\n\r", string $producer = 'utf8.string', string $path = 'value'): string
    {
        self::assertValid($value, $producer, $path);
        $class = preg_quote($characters, '/');
        $result = preg_replace('/\A[' . $class . ']+|[' . $class . ']+\z/u', '', $value);
        if ($result === null) throw new Utf8ValidationException($producer, $path);
        return $result;
    }

    private static function assertValid(string $value, string $producer, string $path): void
    {
        Utf8Contract::assertValid($value, $producer, $path);
    }

    /** @return list<string> */
    private static function characters(string $value, string $producer, string $path): array
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) throw new Utf8ValidationException($producer, $path);
        return array_values($characters);
    }
}
