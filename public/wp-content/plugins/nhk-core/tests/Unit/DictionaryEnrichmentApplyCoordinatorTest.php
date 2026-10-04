<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryEnrichmentApplyCoordinator;
use PHPUnit\Framework\TestCase;

final class DictionaryEnrichmentApplyCoordinatorTest extends TestCase
{
    public function test_multiple_actions_refresh_revision_after_each_successful_mutation(): void
    {
        $revision = 1;
        $receipts = [];
        $calls = [];
        $coordinator = new DictionaryEnrichmentApplyCoordinator(
            static function (array $action, int $currentRevision, string $key) use (&$calls, &$revision): array {
                $calls[] = [$action['action_type'], $currentRevision, $key];
                return ['entry_revision' => ++$revision, 'action' => $action['action_type']];
            },
            static function (string $key) use (&$receipts): ?array { return $receipts[$key] ?? null; },
            static function (string $key, array $result) use (&$receipts): void { $receipts[$key] = $result; },
            static function () use (&$revision): int { return $revision; },
        );

        $result = $coordinator->apply([
            ['action_type' => 'SET_SEMANTIC_REFERENCE', 'status' => 'READY', 'entry_id' => 'entry-1', 'current_revision' => 1],
            ['action_type' => 'ADD_ENTRY_FORM', 'status' => 'READY', 'entry_id' => 'entry-1', 'current_revision' => 1],
            ['action_type' => 'ADD_ENTRY_FORM', 'status' => 'READY', 'entry_id' => 'entry-1', 'current_revision' => 1],
        ], 'plan-1');

        self::assertSame('applied', $result['status']);
        self::assertSame([1, 2, 3], array_column($calls, 1));
        self::assertSame(4, $result['entry_revisions']['entry-1']);
    }

    public function test_failure_returns_explicit_partial_receipt_and_replay_resumes(): void
    {
        $revision = 1;
        $attempts = 0;
        $receipts = [];
        $coordinator = new DictionaryEnrichmentApplyCoordinator(
            static function (array $action, int $currentRevision, string $key) use (&$revision, &$attempts): array {
                $attempts++;
                if ($attempts === 2) throw new \RuntimeException('DICTIONARY_ENTRY_REVISION_CONFLICT');
                return ['entry_revision' => ++$revision, 'action' => $action['action_type']];
            },
            static function (string $key) use (&$receipts): ?array { return $receipts[$key] ?? null; },
            static function (string $key, array $result) use (&$receipts): void { $receipts[$key] = $result; },
            static function () use (&$revision): int { return $revision; },
        );
        $actions = [
            ['action_type' => 'SET_SEMANTIC_REFERENCE', 'status' => 'READY', 'entry_id' => 'entry-1', 'current_revision' => 1],
            ['action_type' => 'ADD_ENTRY_FORM', 'status' => 'READY', 'entry_id' => 'entry-1', 'current_revision' => 1],
            ['action_type' => 'ADD_ENTRY_FORM', 'status' => 'READY', 'entry_id' => 'entry-1', 'current_revision' => 1],
        ];

        $failed = $coordinator->apply($actions, 'plan-1');
        self::assertSame('partial', $failed['status']);
        self::assertSame(1, $failed['applied_count']);
        self::assertSame('DICTIONARY_ENTRY_REVISION_CONFLICT', $failed['error']['code']);

        $resumed = $coordinator->apply($actions, 'plan-1');
        self::assertSame('applied', $resumed['status']);
        self::assertSame(3, $resumed['applied_count']);
        self::assertSame(4, $attempts);
    }

    public function test_external_stale_revision_fails_before_mutation(): void
    {
        $receipts = [];
        $calls = 0;
        $coordinator = new DictionaryEnrichmentApplyCoordinator(
            static function (array $action, int $revision, string $key) use (&$calls): array { $calls++; return ['entry_revision' => $revision + 1]; },
            static function (string $key) use (&$receipts): ?array { return $receipts[$key] ?? null; },
            static function (string $key, array $result) use (&$receipts): void { $receipts[$key] = $result; },
            static fn (): int => 2,
        );

        $result = $coordinator->apply([
            ['action_type' => 'ADD_ENTRY_FORM', 'status' => 'READY', 'entry_id' => 'entry-1', 'current_revision' => 1],
        ], 'plan-1');

        self::assertSame('blocked', $result['status']);
        self::assertSame('DICTIONARY_ENTRY_REVISION_CONFLICT', $result['error']['code']);
        self::assertSame(0, $calls);
    }
}
