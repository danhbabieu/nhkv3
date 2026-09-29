<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryRelationHandoff;
use PHPUnit\Framework\TestCase;

final class DictionaryRelationHandoffTest extends TestCase
{
    public function test_registered_unique_owner_returns_reviewable_packet_without_apply(): void
    {
        $handoff = new DictionaryRelationHandoff(
            static fn (string $type, string $id): ?array => ['type' => $type, 'id' => $id, 'revision' => 4],
            static fn (string $sourceType, string $predicate, string $targetType): bool => $predicate === 'about',
        );
        $result = $handoff->prepare(['concept_id' => 'lex-1', 'owner_type' => 'model', 'owner_id' => 'model-1', 'predicate' => 'about', 'target_type' => 'knowledge', 'target_id' => 'claim-1', 'provenance' => ['source_kind' => 'ARTICLE'], 'idempotency_key' => 'handoff-1']);
        self::assertSame('REVIEW_REQUIRED', $result['status']);
        self::assertSame('model-1', $result['source']['id']);
        self::assertFalse($result['applied']);
    }

    public function test_ambiguous_or_unregistered_handoff_fails_closed(): void
    {
        $handoff = new DictionaryRelationHandoff(static fn (): array => [], static fn (): bool => false);
        $result = $handoff->prepare(['concept_id' => 'lex-1', 'owner_type' => 'model', 'owner_id' => 'model-1', 'predicate' => 'invented', 'target_type' => 'knowledge', 'target_id' => 'claim-1', 'idempotency_key' => 'handoff-2']);
        self::assertSame('REGISTRY_GAP', $result['status']);
        self::assertFalse($result['applied']);
    }
}
