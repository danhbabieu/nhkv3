<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Semantic\{EnrichmentPack, SemanticInputEnvelope, SemanticNeed, UniversalInputEnvelope};
use PHPUnit\Framework\TestCase;

final class UniversalEnrichmentContractTest extends TestCase
{
    public function test_semantic_need_preserves_distinct_target_subject_and_owner_context(): void
    {
        $need = SemanticNeed::fromArray([
            'canonical_subject' => ['id' => 'subject-a', 'type' => 'model'],
            'target_subject' => ['id' => 'subject-b', 'type' => 'model'],
            'owner_context' => ['owner_id' => 'article-1', 'owner_type' => 'article'],
            'facet_key' => 'dimensions',
        ]);

        self::assertSame('subject-a', $need->canonicalSubject()['id']);
        self::assertSame('subject-b', $need->targetSubject()['id']);
        self::assertSame('article-1', $need->ownerContext()['owner_id']);
    }

    public function test_universal_input_preserves_optional_context_and_normalizes_origins(): void
    {
        $input = UniversalInputEnvelope::fromArray([
            'owner_or_source_type' => 'media_image',
            'source_identity' => ['type' => 'external_url', 'id' => 'source-1'],
            'title' => 'A specimen image',
            'body' => 'Visible marks and dimensions.',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'variant', 'revision' => 4]],
            'observations' => [['value' => 'blue dial', 'origin' => 'specimen_observation']],
            'relations' => [['predicate' => 'depicts', 'target_id' => 'subject-1']],
            'existing_knowledge' => [['claim_id' => 'claim-1', 'revision' => 2]],
            'provenance' => ['source_id' => 'source-1'],
            'confidence' => 0.75,
            'constraints' => ['max_candidates' => 4],
        ]);

        self::assertSame('media_image', $input->toArray()['owner_or_source_type']);
        self::assertSame('A specimen image', $input->toArray()['title']);
        self::assertSame('Visible marks and dimensions.', $input->toArray()['body']);
        self::assertSame('SPECIMEN_OBSERVATION', $input->toArray()['observations'][0]['origin']);
        self::assertSame(['id' => 'subject-1', 'type' => 'variant', 'revision' => 4], $input->toArray()['subject_resolution']['primary']);
        self::assertSame([['claim_id' => 'claim-1', 'revision' => 2]], $input->toArray()['existing_knowledge']);
        self::assertSame(0.75, $input->toArray()['confidence']);
    }

    public function test_missing_fields_are_explicitly_sparse_and_never_inferred_from_title(): void
    {
        $input = UniversalInputEnvelope::fromArray(['title' => 'Variant 36/8']);
        $value = $input->toArray();

        self::assertSame('', $value['body']);
        self::assertSame([], $value['subject_resolution']);
        self::assertContains('CANONICAL_SUBJECT_UNAVAILABLE', $value['diagnostics']);
        self::assertNotSame('variant', $value['subject_resolution']['primary']['type'] ?? null);
    }

    public function test_raw_input_alias_is_preserved_as_transient_body_context(): void
    {
        $input = UniversalInputEnvelope::fromArray([
            'owner_or_source_type' => 'video',
            'raw_input' => 'Odo 36',
            'subject_resolution' => ['primary' => ['id' => 'subject-1', 'type' => 'model']],
        ]);

        self::assertSame('Odo 36', $input->toArray()['body']);
        self::assertSame('Odo 36', $input->toArray()['raw_text']);
        self::assertNotContains('INPUT_CONTENT_UNAVAILABLE', $input->toArray()['diagnostics']);
    }

    public function test_non_scalar_text_aliases_fail_closed_without_array_to_string_warnings(): void
    {
        $input = UniversalInputEnvelope::fromArray([
            'title' => ['unexpected' => 'shape'],
            'body' => ['unexpected' => 'shape'],
        ]);

        self::assertSame('', $input->toArray()['title']);
        self::assertSame('', $input->toArray()['body']);
        self::assertContains('INPUT_CONTENT_UNAVAILABLE', $input->toArray()['diagnostics']);
    }

    public function test_legacy_semantic_input_delegates_to_the_universal_contract(): void
    {
        $legacy = SemanticInputEnvelope::fromArray([
            'input_type' => 'article',
            'raw_text' => 'A note',
            'observations' => [['value' => 'maker', 'origin' => 'user_explicit']],
        ]);

        self::assertSame('article', $legacy->toArray()['owner_or_source_type']);
        self::assertSame('A note', $legacy->toArray()['raw_text']);
        self::assertSame('USER_EXPLICIT', $legacy->toArray()['observations'][0]['origin']);
    }

    public function test_enrichment_pack_has_independent_bounded_branches_and_stable_serialization(): void
    {
        $pack = EnrichmentPack::fromBranches([
            'content' => ['status' => 'AVAILABLE', 'selected' => [['claim_id' => 'claim-1']], 'diagnostics' => []],
            'relations' => ['status' => 'INCOMPLETE', 'candidates' => [], 'readiness' => ['status' => 'OPTIONAL_ENRICHMENT'], 'diagnostics' => ['RELATION_PLANNER_UNAVAILABLE']],
            'knowledge' => ['status' => 'NOT_REQUESTED', 'candidates' => [], 'proposals' => [], 'diagnostics' => []],
        ]);

        self::assertSame(['content', 'relations', 'knowledge'], array_keys($pack->toArray()));
        self::assertSame('AVAILABLE', $pack->toArray()['content']['status']);
        self::assertSame('INCOMPLETE', $pack->toArray()['relations']['status']);
        self::assertSame('NOT_REQUESTED', $pack->toArray()['knowledge']['status']);
        self::assertSame($pack->toArray(), EnrichmentPack::fromBranches($pack->toArray())->toArray());
    }

    public function test_enrichment_pack_rejects_unknown_branch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EnrichmentPack::fromBranches(['content' => ['status' => 'AVAILABLE'], 'video' => ['status' => 'AVAILABLE']]);
    }
}
