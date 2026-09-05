<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryObservationRegistry
{
    private static $observer = null;
    private static $previewer = null;

    public static function register(callable $observer, callable $previewer): void
    {
        self::$observer = $observer;
        self::$previewer = $previewer;
    }

    public static function observe(string $sourceKind, string $sourceId, string $text, array $context = [], array $hints = []): array
    {
        if (!is_callable(self::$observer)) return ['status' => 'NOT_CONFIGURED', 'blocking' => false];
        try { $result = (self::$observer)($sourceKind, $sourceId, $text, $context, $hints); return is_array($result) ? $result : ['status' => 'UNAVAILABLE', 'blocking' => false]; }
        catch (\Throwable) { return ['status' => 'UNAVAILABLE', 'blocking' => false, 'warnings' => ['DICTIONARY_OBSERVATION_UNAVAILABLE']]; }
    }

    public static function preview(string $sourceKind, string $text, array $context = [], array $hints = []): array
    {
        if (!is_callable(self::$previewer)) return ['status' => 'NOT_CONFIGURED', 'blocking' => false];
        try { $result = (self::$previewer)($sourceKind, $text, $context, $hints); return is_array($result) ? $result : ['status' => 'UNAVAILABLE', 'blocking' => false]; }
        catch (\Throwable) { return ['status' => 'UNAVAILABLE', 'blocking' => false, 'warnings' => ['DICTIONARY_PLANNING_UNAVAILABLE']]; }
    }
}
