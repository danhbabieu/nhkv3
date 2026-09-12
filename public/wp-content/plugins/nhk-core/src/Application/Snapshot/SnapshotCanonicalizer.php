<?php
declare(strict_types=1);

namespace NHK\Core\Application\Snapshot;

final class SnapshotCanonicalizer
{
    /** @param list<array<string,mixed>> $records */
    public static function records(array $records): array
    {
        $clean = [];
        foreach ($records as $record) {
            if (!is_array($record)) throw new \InvalidArgumentException('SNAPSHOT_RECORD_MUST_BE_OBJECT');
            if (preg_match('/password|secret|token|credential|private[_-]?key/i', (string) ($record['option_name'] ?? '')) === 1) continue;
            $sanitized = self::value($record);
            if (is_array($sanitized)) $clean[] = $sanitized;
        }
        usort($clean, static fn (array $a, array $b): int => self::encode($a) <=> self::encode($b));
        return $clean;
    }

    /** @param array<string,mixed> $collections @return array<string,list<array<string,mixed>>> */
    public static function collections(array $collections): array
    {
        $result = [];
        foreach ($collections as $name => $records) {
            if (!is_string($name) || !is_array($records)) throw new \InvalidArgumentException('SNAPSHOT_COLLECTION_INVALID');
            $result[$name] = self::records($records);
        }
        ksort($result);
        return $result;
    }

    /** @param mixed $value @return mixed */
    private static function value(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $keyString = (string) $key;
                if (self::secretKey($keyString)) continue;
                if ($keyString === 'option_name' && preg_match('/password|secret|token|credential|private[_-]?key/i', (string) $child) === 1) continue;
                $result[$key] = self::value($child);
            }
            if (!array_is_list($result)) ksort($result);
            return $result;
        }
        if (is_object($value) || is_resource($value)) throw new \InvalidArgumentException('SNAPSHOT_VALUE_NOT_SERIALIZABLE');
        return $value;
    }

    private static function secretKey(string $key): bool
    {
        return preg_match('/password|secret|token|private[_-]?key|authorization|credential/i', $key) === 1;
    }

    /** @param mixed $value */
    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param list<array<string,mixed>> $records */
    public static function hashRecords(array $records): string
    {
        return hash('sha256', self::encode(self::records($records)));
    }
}
