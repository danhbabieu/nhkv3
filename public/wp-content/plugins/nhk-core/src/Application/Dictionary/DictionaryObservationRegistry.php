<?php
declare(strict_types=1);

namespace NHK\Core\Application\Dictionary;

final class DictionaryObservationRegistry
{
    private static $observer = null;
    private static $previewer = null;
    private static $ownerPlanner = null;
    private static $ownerApplier = null;

    public static function register(callable $observer, callable $previewer, ?callable $ownerPlanner = null, ?callable $ownerApplier = null): void
    {
        self::$observer = $observer;
        self::$previewer = $previewer;
        self::$ownerPlanner = $ownerPlanner;
        self::$ownerApplier = $ownerApplier;
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

    public static function ownerPlan(string $sourceKind, string $sourceId, string $text, array $context = [], array $observation = []): array
    {
        if (!is_callable(self::$ownerPlanner)) return ['status' => 'NOT_CONFIGURED', 'dictionary_mutation' => false];
        try {
            $result = (self::$ownerPlanner)($sourceKind, $sourceId, $text, $context, $observation);
            return is_array($result) ? $result : ['status' => 'UNAVAILABLE', 'dictionary_mutation' => false];
        } catch (\Throwable) {
            return ['status' => 'UNAVAILABLE', 'dictionary_mutation' => false, 'diagnostics' => ['DICTIONARY_OWNER_PLANNING_UNAVAILABLE']];
        }
    }

    public static function applyOwnerPlan(array $plan, string $idempotencyKey): array
    {
        if (!is_callable(self::$ownerApplier)) return ['status' => 'NOT_CONFIGURED', 'dictionary_mutation' => false];
        try {
            $result = (self::$ownerApplier)($plan, $idempotencyKey);
            return is_array($result) ? $result : ['status' => 'UNAVAILABLE', 'dictionary_mutation' => false];
        } catch (\Throwable $error) {
            return ['status' => 'UNAVAILABLE', 'dictionary_mutation' => false, 'diagnostics' => [preg_replace('/[^A-Z0-9_:-]+/', '_', strtoupper(trim($error->getMessage()))) ?: 'DICTIONARY_OWNER_APPLY_FAILED']];
        }
    }
}
