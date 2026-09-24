<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{ClaimRetrievalEngine, EditorialClaimRetrievalService, EditorialKnowledgeSelector, EnrichmentPack, UniversalEnrichmentCore, UniversalInputEnvelope};
use PHPUnit\Framework\TestCase;

final class UniversalEnrichmentCoreTest extends TestCase
{
    public function test_content_relation_and_knowledge_branches_are_independent_and_deterministic(): void
    {
        $core = $this->core(static fn (array $request): array => [['candidate_id' => 'relation-1']]);
        $input = UniversalInputEnvelope::fromArray([
            'owner_or_source_type' => 'generic_source',
            'body' => 'A documented fact.',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'model']],
            'relations' => [['predicate' => 'about', 'target_id' => 'target-1']],
        ]);

        $left = $core->enrich($input, ['profile' => 'media', 'topic' => 'fact', 'relations' => [['predicate' => 'about']]]);
        $right = $core->enrich($input, ['profile' => 'media', 'topic' => 'fact', 'relations' => [['predicate' => 'about']]]);

        self::assertInstanceOf(EnrichmentPack::class, $left);
        $leftArray = $left->toArray();
        $rightArray = $right->toArray();
        unset($leftArray['content']['pack'], $rightArray['content']['pack']);
        self::assertSame($leftArray, $rightArray);
        self::assertSame('AVAILABLE', $left->toArray()['relations']['status']);
        self::assertSame('NOT_REQUESTED', $left->toArray()['knowledge']['status']);
        self::assertSame('AVAILABLE', $left->toArray()['content']['status']);
    }

    public function test_sparse_content_is_local_and_does_not_claim_knowledge_or_relation_success(): void
    {
        $core = $this->core();
        $pack = $core->enrich(UniversalInputEnvelope::fromArray(['owner_or_source_type' => 'media_image', 'title' => 'Sparse']), ['profile' => 'media', 'topic' => 'Sparse']);

        self::assertContains('SHARED_CONTENT_CONTEXT_SPARSE', $pack->toArray()['content']['diagnostics']);
        self::assertSame('NOT_REQUESTED', $pack->toArray()['knowledge']['status']);
        self::assertSame('NOT_REQUESTED', $pack->toArray()['relations']['status']);
    }

    private function core(?callable $relations = null): UniversalEnrichmentCore
    {
        $engine = new ClaimRetrievalEngine(
            static fn (array $subject): array => ['status' => 'available', 'items' => []],
            static fn (array $subject, array $neighborhood): array => [[
                'claim_id' => 'claim-1', 'revision' => 1, 'text' => 'A documented fact.', 'subject_id' => $subject['id'], 'subject_type' => $subject['type'], 'scope' => 'model', 'provenance' => 'SOURCE_EXPLICIT', 'evidence_status' => 'SUPPORTED_WITHIN_SCOPE',
            ]],
        );
        return new UniversalEnrichmentCore(new EditorialClaimRetrievalService($engine), new EditorialKnowledgeSelector(), null, null, $relations);
    }
}
