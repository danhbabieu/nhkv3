<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, UniversalEnrichmentCore, UniversalInputEnvelope};
use PHPUnit\Framework\TestCase;

final class UniversalRelationBranchTest extends TestCase
{
    public function test_relation_intents_are_read_only_candidates_and_never_applied(): void
    {
        $applyCalls = 0;
        $core = new UniversalEnrichmentCore(
            new EditorialClaimRetrievalService(new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => [])),
            new EditorialKnowledgeSelector(),
            null,
            null,
            static function (array $options) use (&$applyCalls): array {
                $applyCalls++;
                return [['candidate_id' => 'relation-1', 'predicate' => 'about', 'applied' => false]];
            },
        );
        $pack = $core->enrich(UniversalInputEnvelope::fromArray([
            'owner_or_source_type' => 'video',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant']],
            'relations' => [['predicate' => 'about', 'target_id' => 'target-1']],
        ]), ['profile' => 'video']);

        self::assertSame('AVAILABLE', $pack->toArray()['relations']['status']);
        self::assertFalse($pack->toArray()['relations']['readiness']['applied']);
        self::assertSame(1, $applyCalls);
    }
}
