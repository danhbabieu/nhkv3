<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Audit\{DictionaryDuplicateAuditAdapter, SystemWideDuplicateAuditCoordinator};
use NHK\Core\Application\Dictionary\DictionaryDuplicateCandidateAudit;
use NHK\Core\Contracts\Audit\DuplicateAuditPageReader;
use NHK\Core\Contracts\Dictionary\DictionaryDuplicateAuditReader;
use PHPUnit\Framework\TestCase;

final class SystemWideDuplicateAuditTest extends TestCase
{
    public function test_dictionary_reuses_existing_audit_and_reports_con_hoa_thi(): void
    {
        $reader = new class implements DictionaryDuplicateAuditReader {
            public function read(int $limit = 1000): array
            {
                return [
                    ['entry_id' => 'dict-1', 'form_id' => 'form-1', 'sense_id' => 'sense-1', 'form_text' => 'Côn hoa thị', 'normalized_form' => 'côn hoa thị', 'entry_status' => 'APPROVED', 'sense_status' => 'APPROVED', 'entry_revision' => 2, 'sense_revision' => 3, 'context' => ['family' => 'clock'], 'state' => 1],
                    ['entry_id' => 'dict-2', 'form_id' => 'form-2', 'sense_id' => 'sense-2', 'form_text' => 'Côn hoa thị', 'normalized_form' => 'côn hoa thị', 'entry_status' => 'APPROVED', 'sense_status' => 'APPROVED', 'entry_revision' => 4, 'sense_revision' => 1, 'context' => ['family' => 'clock'], 'state' => 1],
                ];
            }
        };
        $adapter = new DictionaryDuplicateAuditAdapter(new DictionaryDuplicateCandidateAudit($reader));
        $result = $adapter->run(50, null);

        self::assertSame('COMPLETE', $result['status']);
        self::assertSame('dictionary:côn hoa thị', $result['clusters'][0]['cluster_id']);
        self::assertSame(['dict-1', 'dict-2'], $result['clusters'][0]['canonical_ids']);
        self::assertTrue($result['clusters'][0]['cursor_page_provenance']['read_only']);
    }

    public function test_authority_uses_stable_key_and_family_name_signals(): void
    {
        $result = $this->audit('Authority', [
            ['canonical_id' => 'a-1', 'stable_key' => 'authority.one', 'entity_type' => 'classification', 'canonical_name' => 'Côn', 'family' => 'clock', 'revision' => 1],
            ['canonical_id' => 'a-2', 'stable_key' => 'authority.one', 'entity_type' => 'classification', 'canonical_name' => 'Côn', 'family' => 'clock', 'revision' => 2],
        ]);
        self::assertContains('same_stable_key_structural_collision', $result['owners']['Authority']['clusters'][0]['reasons']);
    }

    public function test_knowledge_separates_exact_proposition_from_qualification_variant(): void
    {
        $rows = [
            ['canonical_id' => 'k-1', 'claim_text' => 'X dùng máy M', 'claim_type' => 'fact', 'subject_id' => 'subject-1', 'facet' => 'movement', 'scope' => 'variant', 'revision' => 1],
            ['canonical_id' => 'k-2', 'claim_text' => 'X dùng máy M', 'claim_type' => 'fact', 'subject_id' => 'subject-1', 'facet' => 'movement', 'scope' => 'variant', 'revision' => 2],
        ];
        $result = $this->audit('Knowledge', $rows);
        self::assertSame('DEFINITE_DUPLICATE', $result['owners']['Knowledge']['clusters'][0]['classification']);
    }

    public function test_video_provenance_claims_with_different_video_referents_are_distinct(): void
    {
        $proposition = 'The source identifies this Video as concerning canonical Odo 62.';
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'k-1', 'claim_text' => $proposition, 'claim_type' => 'provenance', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-1', 'platform' => 'youtube', 'external_video_id' => 'video-a']], 'revision' => 1],
            ['canonical_id' => 'k-2', 'claim_text' => $proposition, 'claim_type' => 'provenance', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-1', 'platform' => 'youtube', 'external_video_id' => 'video-b']], 'revision' => 1],
        ]);

        self::assertSame([], $result['owners']['Knowledge']['clusters']);
    }

    public function test_same_video_provenance_claim_is_still_a_definite_duplicate(): void
    {
        $proposition = 'The source identifies this Video as concerning canonical Odo 62.';
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'k-1', 'claim_text' => $proposition, 'claim_type' => 'provenance', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-1', 'platform' => 'youtube', 'external_video_id' => 'video-a', 'source_stable_key' => 'source-a']], 'revision' => 1],
            ['canonical_id' => 'k-2', 'claim_text' => $proposition, 'claim_type' => 'provenance', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-1', 'platform' => 'youtube', 'external_video_id' => 'video-a', 'source_stable_key' => 'source-b']], 'revision' => 1],
        ]);

        self::assertSame('DEFINITE_DUPLICATE', $result['owners']['Knowledge']['clusters'][0]['classification']);
    }

    public function test_same_video_provenance_with_changed_wording_is_still_one_identity(): void
    {
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'k-1', 'claim_text' => 'The source identifies this Video as concerning Odo 62.', 'claim_type' => 'provenance', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-1', 'platform' => 'youtube', 'external_video_id' => 'video-a']], 'revision' => 1],
            ['canonical_id' => 'k-2', 'claim_text' => 'This canonical Video concerns Odo 62.', 'claim_type' => 'provenance', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-1', 'platform' => 'youtube', 'external_video_id' => 'video-a']], 'revision' => 1],
        ]);

        self::assertSame('DEFINITE_DUPLICATE', $result['owners']['Knowledge']['clusters'][0]['classification']);
    }

    public function test_ordinary_claim_identity_ignores_source_locator_and_request_metadata(): void
    {
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'k-1', 'claim_text' => 'X dùng máy M', 'claim_type' => 'fact', 'provenance' => ['metadata' => ['subject_id' => 'subject-1', 'facet' => 'movement', 'scope' => 'variant', 'source_locator' => 'https://example.test/a', 'request_key' => 'request-a']], 'revision' => 1],
            ['canonical_id' => 'k-2', 'claim_text' => 'X dùng máy M', 'claim_type' => 'fact', 'provenance' => ['metadata' => ['subject_id' => 'subject-1', 'facet' => 'movement', 'scope' => 'variant', 'source_locator' => 'https://example.test/b', 'request_key' => 'request-b']], 'revision' => 1],
        ]);

        self::assertSame('DEFINITE_DUPLICATE', $result['owners']['Knowledge']['clusters'][0]['classification']);
    }

    public function test_missing_video_identity_is_coverage_diagnostic_without_duplicate_cluster(): void
    {
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'k-1', 'claim_text' => 'Video concerns Odo 62.', 'claim_type' => 'provenance', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-1']], 'revision' => 1],
            ['canonical_id' => 'k-2', 'claim_text' => 'Video concerns another subject.', 'claim_type' => 'provenance', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-1']], 'revision' => 1],
        ]);

        self::assertSame([], $result['owners']['Knowledge']['clusters']);
        self::assertSame(0, $result['owners']['Knowledge']['diagnostics']['identity_resolved_rows']);
        self::assertSame(2, $result['owners']['Knowledge']['diagnostics']['identity_unresolved_rows']);
        self::assertSame(0, $result['owners']['Knowledge']['diagnostics']['identity_conflicting_rows']);
        self::assertCount(2, $result['owners']['Knowledge']['diagnostics']['bounded_identity_review_samples']);
        self::assertSame(['video_referent.platform', 'video_referent.external_video_id'], $result['owners']['Knowledge']['diagnostics']['bounded_identity_review_samples'][0]['missing_fields']);
        self::assertSame(1, $result['owners']['Knowledge']['diagnostics']['bounded_identity_review_samples'][0]['revision']);
        self::assertSame('ACTIVE', $result['owners']['Knowledge']['diagnostics']['bounded_identity_review_samples'][0]['lifecycle_state']);
        self::assertSame('UNKNOWN', $result['owners']['Knowledge']['diagnostics']['bounded_identity_review_samples'][0]['source_class']);
        self::assertSame('DUPLICATE_GROUPING_EXCLUDED', $result['owners']['Knowledge']['diagnostics']['bounded_identity_review_samples'][0]['coverage_impact']);
    }

    public function test_two_unresolved_ordinary_rows_never_form_duplicate_cluster(): void
    {
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'k-1', 'claim_text' => 'Same text', 'claim_type' => 'fact', 'revision' => 1],
            ['canonical_id' => 'k-2', 'claim_text' => 'Same text', 'claim_type' => 'fact', 'revision' => 1],
        ]);

        self::assertSame([], $result['owners']['Knowledge']['clusters']);
        self::assertSame(2, $result['owners']['Knowledge']['diagnostics']['identity_unresolved_rows']);
    }

    public function test_knowledge_marks_nonidentical_wording_as_possible_and_qualification_as_scoped(): void
    {
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'k-1', 'claim_text' => 'X dùng máy M', 'subject_id' => 'subject-1', 'facet' => 'movement', 'scope' => 'variant', 'revision' => 1],
            ['canonical_id' => 'k-2', 'claim_text' => 'X sử dụng máy M', 'subject_id' => 'subject-1', 'facet' => 'movement', 'scope' => 'variant', 'revision' => 1],
            ['canonical_id' => 'k-3', 'claim_text' => 'X dùng máy M khi chạy nhanh', 'subject_id' => 'subject-1', 'facet' => 'power', 'scope' => 'variant', 'qualification' => 'khi chạy nhanh', 'revision' => 1],
            ['canonical_id' => 'k-4', 'claim_text' => 'X dùng máy M khi chạy chậm', 'subject_id' => 'subject-1', 'facet' => 'power', 'scope' => 'variant', 'qualification' => 'khi chạy chậm', 'revision' => 1],
        ]);
        $classes = array_column($result['owners']['Knowledge']['clusters'], 'classification');
        self::assertContains('POSSIBLE_DUPLICATE', $classes);
        self::assertContains('CONTEXTUAL_OR_SCOPED_VARIANT', $classes);
    }

    public function test_same_label_in_different_authority_family_is_not_collapsed(): void
    {
        $result = $this->audit('Authority', [
            ['canonical_id' => 'a-1', 'stable_key' => 'clock.con', 'entity_type' => 'classification', 'canonical_name' => 'Côn', 'family' => 'clock', 'revision' => 1],
            ['canonical_id' => 'a-2', 'stable_key' => 'pen.con', 'entity_type' => 'classification', 'canonical_name' => 'Côn', 'family' => 'pen', 'revision' => 1],
        ]);
        self::assertSame([], $result['owners']['Authority']['clusters']);
    }

    public function test_page_boundary_is_partial_and_resumable(): void
    {
        $reader = new class implements DuplicateAuditPageReader {
            public function page(?string $after, int $limit): array { return ['items' => [['canonical_id' => 'a-1', 'stable_key' => 'same']], 'next_cursor' => 'a-1']; }
        };
        $result = $this->coordinator(['Authority' => $reader])->audit(1, [], true, 'Authority');
        self::assertSame('PARTIAL', $result['owners']['Authority']['status']);
        self::assertNotSame('a-1', $result['owners']['Authority']['next_cursor']);
        self::assertNotEmpty($result['owners']['Authority']['next_cursor']);
        self::assertSame($result['owners']['Authority']['next_cursor'], $result['owners']['Authority']['cursor_page_provenance']['next_cursor']);
    }

    public function test_duplicate_cluster_crosses_page_boundary_without_losing_retired_state(): void
    {
        $reader = new BoundaryAuditPage([
            [['canonical_id' => 'a-1', 'stable_key' => 'same', 'entity_type' => 'component', 'canonical_name' => 'Côn', 'state' => 'RETIRED', 'revision' => 1]],
            [['canonical_id' => 'a-2', 'stable_key' => 'same', 'entity_type' => 'component', 'canonical_name' => 'Côn', 'state' => 'ACTIVE', 'revision' => 2]],
        ]);
        $coordinator = $this->coordinator(['Authority' => $reader]);

        $first = $coordinator->audit(1);
        self::assertSame('PARTIAL', $first['owners']['Authority']['status']);
        self::assertSame([], $first['owners']['Authority']['clusters']);

        $second = $coordinator->audit(1, ['Authority' => $first['owners']['Authority']['next_cursor']]);
        self::assertSame('COMPLETE', $second['owners']['Authority']['status']);
        self::assertSame(['a-1', 'a-2'], $second['owners']['Authority']['clusters'][0]['canonical_ids']);
        $states = $second['owners']['Authority']['clusters'][0]['active_states'];
        sort($states);
        self::assertSame(['ACTIVE', 'RETIRED'], $states);
    }

    public function test_paginated_duplicate_cluster_is_emitted_once_when_it_reappears_after_carry(): void
    {
        $reader = new BoundaryAuditPage([
            [
                ['canonical_id' => 'a-1', 'stable_key' => 'same', 'entity_type' => 'component', 'canonical_name' => 'Côn'],
                ['canonical_id' => 'a-2', 'stable_key' => 'same', 'entity_type' => 'component', 'canonical_name' => 'Côn'],
            ],
            [
                ['canonical_id' => 'a-3', 'stable_key' => 'same', 'entity_type' => 'component', 'canonical_name' => 'Côn'],
            ],
        ]);
        $coordinator = $this->coordinator(['Authority' => $reader]);
        $first = $coordinator->audit(2, [], true, 'Authority');
        $second = $coordinator->audit(1, ['Authority' => $first['owners']['Authority']['next_cursor']], true, 'Authority');

        self::assertCount(1, $first['owners']['Authority']['clusters']);
        self::assertSame([], $second['owners']['Authority']['clusters']);
    }

    public function test_cursor_stays_within_mcp_transport_limit_when_carry_is_large(): void
    {
        $rows = [];
        for ($i = 1; $i <= 128; $i++) {
            $rows[] = [
                'canonical_id' => 'a-' . $i,
                'stable_key' => 'shared-key',
                'entity_type' => 'component',
                'canonical_name' => str_repeat('Côn ', 24) . $i,
                'family' => 'clock',
                'state' => 'ACTIVE',
                'revision' => $i,
            ];
        }
        $reader = new class($rows) implements DuplicateAuditPageReader {
            public function __construct(private array $rows) {}
            public function page(?string $after, int $limit): array
            {
                return ['items' => array_slice($this->rows, 0, $limit), 'next_cursor' => 'more'];
            }
        };

        $result = $this->coordinator(['Authority' => $reader])->audit(128, [], true, 'Authority');
        $cursor = $result['owners']['Authority']['next_cursor'];

        self::assertIsString($cursor);
        self::assertLessThanOrEqual(4096, strlen($cursor));
    }

    public function test_every_bounded_non_article_owner_can_resume_a_partial_cursor(): void
    {
        foreach (['Authority', 'Knowledge', 'Source', 'Evidence', 'Graph', 'Media', 'MediaAsset', 'MediaUsage', 'Video'] as $owner) {
            $reader = new RepeatingBoundedAuditPage();
            $coordinator = $this->coordinator([$owner => $reader]);
            $cursor = null;
            $result = [];
            for ($page = 0; $page < 25; $page++) {
                $result = $coordinator->audit(200, $cursor === null ? [] : [$owner => $cursor], true, $owner);
                self::assertNotSame('BLOCKED', $result['owners'][$owner]['status'], $owner);
                $cursor = $result['owners'][$owner]['next_cursor'];
            }
            self::assertSame('PARTIAL', $result['owners'][$owner]['status'], $owner);
            self::assertLessThanOrEqual(4096, strlen((string) $cursor), $owner);
        }
    }

    public function test_page_boundary_keeps_distinct_records_and_singletons_distinct(): void
    {
        $reader = new BoundaryAuditPage([
            [['canonical_id' => 'a-1', 'stable_key' => 'first', 'entity_type' => 'component', 'canonical_name' => 'Một']],
            [['canonical_id' => 'a-2', 'stable_key' => 'second', 'entity_type' => 'component', 'canonical_name' => 'Hai']],
        ]);
        $coordinator = $this->coordinator(['Authority' => $reader]);
        $first = $coordinator->audit(1);
        $second = $coordinator->audit(1, ['Authority' => $first['owners']['Authority']['next_cursor']]);
        self::assertSame([], $second['owners']['Authority']['clusters']);
        self::assertSame('COMPLETE', $second['owners']['Authority']['status']);
    }

    public function test_partial_safety_bound_never_reports_complete(): void
    {
        $reader = new RepeatingBoundedAuditPage();
        $coordinator = $this->coordinator(['Authority' => $reader]);
        $cursor = null;
        $result = [];
        for ($page = 0; $page < 25; $page++) {
            $result = $coordinator->audit(200, $cursor === null ? [] : ['Authority' => $cursor]);
            $cursor = $result['owners']['Authority']['next_cursor'];
        }
        self::assertSame('PARTIAL', $result['owners']['Authority']['status']);
        self::assertFalse($result['owners']['Authority']['complete']);
        self::assertSame('AUDIT_MAX_SCAN_BOUND_REACHED', $result['owners']['Authority']['diagnostics'][0]['code']);
    }

    public function test_source_uses_locator_and_does_not_require_same_title(): void
    {
        $result = $this->audit('Source', [
            ['canonical_id' => 's-1', 'stable_key' => 'source.one', 'locator' => 'https://example.test/a', 'revision' => 1],
            ['canonical_id' => 's-2', 'stable_key' => 'source.two', 'locator' => 'https://example.test/a', 'revision' => 2],
        ]);
        self::assertSame('HIGH_CONFIDENCE_EQUIVALENT', $result['owners']['Source']['clusters'][0]['classification']);
    }

    public function test_evidence_requires_support_unit_in_addition_to_claim_and_source(): void
    {
        $result = $this->audit('Evidence', [
            ['canonical_id' => 'e-1', 'claim_id' => 'k-1', 'source_id' => 's-1', 'relation' => 'supports', 'excerpt' => 'same excerpt', 'locator' => 'p1', 'revision' => 1],
            ['canonical_id' => 'e-2', 'claim_id' => 'k-1', 'source_id' => 's-1', 'relation' => 'supports', 'excerpt' => 'same excerpt', 'locator' => 'p1', 'revision' => 1],
        ]);
        self::assertCount(1, $result['owners']['Evidence']['clusters']);
        self::assertStringContainsString('support_unit', $result['owners']['Evidence']['clusters'][0]['identity_signals'][0]);
    }

    public function test_graph_marks_active_and_retired_history_for_review(): void
    {
        $result = $this->audit('Graph', [
            ['canonical_id' => 'g-1', 'source_type' => 'authority', 'source_id' => 'a', 'predicate' => 'related_to', 'target_type' => 'authority', 'target_id' => 'b', 'state' => 'ACTIVE', 'revision' => 1],
            ['canonical_id' => 'g-2', 'source_type' => 'authority', 'source_id' => 'a', 'predicate' => 'related_to', 'target_type' => 'authority', 'target_id' => 'b', 'state' => 'RETIRED', 'revision' => 2],
        ]);
        self::assertSame('REVIEW_REQUIRED', $result['owners']['Graph']['clusters'][0]['classification']);
    }

    public function test_article_does_not_deduplicate_continuation_articles(): void
    {
        $result = $this->audit('Article', [
            ['canonical_id' => 'p-1', 'subject_ids' => ['a'], 'intent' => 'history', 'scope' => 'variant', 'continuation_lineage' => [], 'semantic_identity_available' => true, 'content_kind' => 'TEXT_ARTICLE'],
            ['canonical_id' => 'p-2', 'subject_ids' => ['a'], 'intent' => 'history', 'scope' => 'variant', 'continuation_lineage' => ['p-1'], 'semantic_identity_available' => true, 'content_kind' => 'CONTINUATION'],
        ]);
        self::assertSame('LEGITIMATE_DISTINCT', $result['owners']['Article']['clusters'][0]['classification']);
    }

    public function test_article_rows_without_semantic_identity_are_blocked_as_model_gap(): void
    {
        $result = $this->audit('Article', [
            ['canonical_id' => 'p-1', 'title' => 'Same title'],
            ['canonical_id' => 'p-2', 'title' => 'Same title'],
        ]);

        self::assertSame('BLOCKED', $result['owners']['Article']['status']);
        self::assertSame('AUDIT_MODEL_GAP', $result['owners']['Article']['diagnostics']['code']);
        self::assertSame('AUDIT_MODEL_GAP', $result['owners']['Article']['diagnostics']['reason']);
        self::assertSame('Article', $result['owners']['Article']['diagnostics']['blocking_owner']);
        self::assertSame('article_semantic_projection', $result['owners']['Article']['diagnostics']['blocking_stage']);
        self::assertSame(['p-1', 'p-2'], array_column($result['owners']['Article']['diagnostics']['blocking_rows'], 'canonical_id'));
        self::assertSame([], $result['owners']['Article']['clusters']);
    }

    public function test_article_title_equality_alone_never_becomes_duplicate(): void
    {
        $result = $this->audit('Article', [
            ['canonical_id' => 'p-1', 'title' => 'Same title', 'subject_ids' => ['a'], 'intent' => 'history', 'scope' => 'variant', 'continuation_lineage' => [], 'semantic_identity_available' => true],
            ['canonical_id' => 'p-2', 'title' => 'Same title', 'subject_ids' => ['b'], 'intent' => 'history', 'scope' => 'variant', 'continuation_lineage' => [], 'semantic_identity_available' => true],
        ]);

        self::assertSame('COMPLETE', $result['owners']['Article']['status']);
        self::assertSame([], $result['owners']['Article']['clusters']);
    }

    public function test_article_legacy_unresolved_is_partial_and_excluded_from_duplicate_grouping(): void
    {
        $result = $this->audit('Article', [
            ['canonical_id' => 'legacy-article', 'title' => 'Old article', 'identity_classification' => 'LEGACY_UNRESOLVED', 'missing_identity_fields' => ['canonical_subject'], 'identity_reason' => 'NO_ACTIVE_CANONICAL_SUBJECT'],
        ]);

        self::assertSame('PARTIAL', $result['owners']['Article']['status']);
        self::assertSame(1, $result['owners']['Article']['diagnostics']['identity_unresolved_rows']);
        self::assertSame('LEGACY_UNRESOLVED', $result['owners']['Article']['diagnostics']['identity_rows'][0]['classification']);
        self::assertSame([], $result['owners']['Article']['clusters']);
        self::assertSame([], $result['reconciliation_candidates']);
    }

    public function test_article_model_gap_remains_blocked_when_canonical_binding_exists_but_required_field_is_not_persisted(): void
    {
        $result = $this->audit('Article', [
            ['canonical_id' => 'article-with-binding', 'subject_ids' => ['model-1'], 'intent' => 'TEXT_ARTICLE', 'scope' => 'variant', 'identity_classification' => 'MODEL_GAP', 'missing_identity_fields' => ['lineage'], 'identity_reason' => 'ARTICLE_LINEAGE_NOT_PERSISTED'],
        ]);

        self::assertSame('BLOCKED', $result['owners']['Article']['status']);
        self::assertSame('AUDIT_MODEL_GAP', $result['owners']['Article']['diagnostics']['code']);
        self::assertSame(['lineage'], $result['owners']['Article']['diagnostics']['missing_identity_fields']);
        self::assertSame('article-with-binding', $result['owners']['Article']['diagnostics']['blocking_rows'][0]['canonical_id']);
    }

    public function test_article_duplicate_identity_is_checked_only_for_auditable_rows(): void
    {
        $result = $this->audit('Article', [
            ['canonical_id' => 'article-1', 'subject_ids' => ['model-1'], 'intent' => 'TEXT_ARTICLE', 'scope' => 'variant', 'continuation_lineage' => [], 'semantic_identity_available' => true, 'identity_classification' => 'AUDITABLE'],
            ['canonical_id' => 'article-2', 'subject_ids' => ['model-1'], 'intent' => 'TEXT_ARTICLE', 'scope' => 'variant', 'continuation_lineage' => [], 'semantic_identity_available' => true, 'identity_classification' => 'AUDITABLE'],
            ['canonical_id' => 'article-legacy', 'subject_ids' => ['model-1'], 'intent' => 'TEXT_ARTICLE', 'scope' => 'variant', 'identity_classification' => 'LEGACY_UNRESOLVED', 'missing_identity_fields' => ['canonical_subject']],
        ]);

        self::assertSame('PARTIAL', $result['owners']['Article']['status']);
        self::assertCount(1, $result['owners']['Article']['clusters']);
        self::assertSame(['article-1', 'article-2'], $result['owners']['Article']['clusters'][0]['canonical_ids']);
        self::assertSame(1, $result['owners']['Article']['diagnostics']['identity_unresolved_rows']);
    }

    public function test_article_ambiguous_and_retired_bindings_are_legacy_unresolved(): void
    {
        $result = $this->audit('Article', [
            ['canonical_id' => 'ambiguous', 'identity_classification' => 'LEGACY_UNRESOLVED', 'missing_identity_fields' => ['canonical_subject'], 'identity_reason' => 'AMBIGUOUS_ACTIVE_SUBJECT'],
            ['canonical_id' => 'retired-only', 'identity_classification' => 'LEGACY_UNRESOLVED', 'missing_identity_fields' => ['canonical_subject'], 'identity_reason' => 'NO_ACTIVE_CANONICAL_SUBJECT'],
        ]);

        self::assertSame('PARTIAL', $result['owners']['Article']['status']);
        self::assertSame(2, $result['owners']['Article']['diagnostics']['identity_unresolved_rows']);
        self::assertSame(['AMBIGUOUS_ACTIVE_SUBJECT', 'NO_ACTIVE_CANONICAL_SUBJECT'], array_column($result['owners']['Article']['diagnostics']['identity_rows'], 'reason'));
    }

    public function test_malformed_cursor_is_rejected_deterministically(): void
    {
        $result = $this->coordinator(['Authority' => new AuditPage([])])->audit(1, ['Authority' => 'not-a-cursor'], true, 'Authority');

        self::assertSame('BLOCKED', $result['owners']['Authority']['status']);
        self::assertSame('AUDIT_CURSOR_INVALID', $result['owners']['Authority']['diagnostics']['code']);
    }

    public function test_cursor_is_bound_to_owner_and_include_retired(): void
    {
        $reader = new BoundaryAuditPage([
            [['canonical_id' => 'a-1', 'stable_key' => 'same']],
            [['canonical_id' => 'a-2', 'stable_key' => 'same']],
        ]);
        $coordinator = $this->coordinator(['Authority' => $reader, 'Knowledge' => $reader]);
        $first = $coordinator->audit(1, [], true, 'Authority');
        $cursor = $first['owners']['Authority']['next_cursor'];

        $crossOwner = $coordinator->audit(1, ['Knowledge' => $cursor], true, 'Knowledge');
        self::assertSame('AUDIT_CURSOR_INVALID', $crossOwner['owners']['Knowledge']['diagnostics']['code']);

        $changedFilter = $coordinator->audit(1, ['Authority' => $cursor], false, 'Authority');
        self::assertSame('AUDIT_CURSOR_INVALID', $changedFilter['owners']['Authority']['diagnostics']['code']);
    }

    public function test_cursor_carry_and_scanned_count_tampering_are_rejected(): void
    {
        $reader = new BoundaryAuditPage([
            [['canonical_id' => 'a-1', 'stable_key' => 'same']],
            [['canonical_id' => 'a-2', 'stable_key' => 'same']],
        ]);
        $coordinator = $this->coordinator(['Authority' => $reader]);
        $cursor = $coordinator->audit(1, [], true, 'Authority')['owners']['Authority']['next_cursor'];
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);
        $decoded['payload']['carry'][0]['canonical_id'] = 'attacker-id';
        $decoded['payload']['scanned'] = 4999;
        $tampered = rtrim(strtr(base64_encode((string) json_encode($decoded)), '+/', '-_'), '=');

        $result = $coordinator->audit(1, ['Authority' => $tampered], true, 'Authority');

        self::assertSame('BLOCKED', $result['owners']['Authority']['status']);
        self::assertSame('AUDIT_CURSOR_INVALID', $result['owners']['Authority']['diagnostics']['code']);
    }

    public function test_media_asset_keeps_parent_and_binary_boundary(): void
    {
        $result = $this->audit('MediaAsset', [
            ['canonical_id' => 'asset-1', 'media_id' => 'm-1', 'checksum' => 'abc', 'kind' => 'original', 'width' => 10, 'height' => 10],
            ['canonical_id' => 'asset-2', 'media_id' => 'm-1', 'checksum' => 'abc', 'kind' => 'original', 'width' => 10, 'height' => 10],
        ]);
        self::assertSame('HIGH_CONFIDENCE_EQUIVALENT', $result['owners']['MediaAsset']['clusters'][0]['classification']);
    }

    public function test_media_and_usage_have_distinct_identity_rules(): void
    {
        $media = $this->audit('Media', [
            ['canonical_id' => 'm-1', 'stable_key' => 'media.one'],
            ['canonical_id' => 'm-2', 'stable_key' => 'media.one'],
        ]);
        $usage = $this->audit('MediaUsage', [
            ['canonical_id' => 'u-1', 'media_id' => 'm-1', 'endpoint_type' => 'wp_post', 'endpoint_key' => 'post:1', 'role' => 'featured', 'placement_key' => 'hero'],
            ['canonical_id' => 'u-2', 'media_id' => 'm-1', 'endpoint_type' => 'wp_post', 'endpoint_key' => 'post:1', 'role' => 'featured', 'placement_key' => 'hero'],
        ]);
        self::assertSame('DEFINITE_DUPLICATE', $media['owners']['Media']['clusters'][0]['classification']);
        self::assertSame('DEFINITE_DUPLICATE', $usage['owners']['MediaUsage']['clusters'][0]['classification']);
    }

    public function test_video_uses_platform_and_external_video_id(): void
    {
        $result = $this->audit('Video', [
            ['canonical_id' => 'v-1', 'platform' => 'youtube', 'external_video_id' => 'abc'],
            ['canonical_id' => 'v-2', 'platform' => 'youtube', 'external_video_id' => 'abc'],
        ]);
        self::assertSame('same_platform_external_video_id', $result['owners']['Video']['clusters'][0]['reasons'][0]);
    }

    public function test_missing_reader_is_blocked_and_no_reconciliation_can_apply(): void
    {
        $result = (new SystemWideDuplicateAuditCoordinator())->audit(10);
        self::assertSame('BLOCKED', $result['status']);
        self::assertFalse($result['mutated']);
        self::assertSame([], $result['reconciliation_candidates']);
        self::assertSame('AUDIT_MODEL_GAP', $result['owners']['Authority']['diagnostics']['code']);
        self::assertSame(['Dictionary', 'Authority', 'Knowledge', 'Source', 'Evidence', 'Graph', 'Article', 'Media', 'MediaAsset', 'MediaUsage', 'Video'], $result['diagnostics']['blocking_owners']);
        self::assertSame('AUDIT_MODEL_GAP', $result['diagnostics']['blocking_reasons']['Authority']);
    }

    public function test_knowledge_identity_coverage_gap_does_not_become_global_execution_blocker(): void
    {
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'k-unresolved', 'claim_text' => 'Evidence is not a subject.', 'claim_type' => 'fact', 'revision' => 7],
        ]);

        self::assertSame('COMPLETE', $result['status']);
        self::assertSame(['Knowledge' => 'COMPLETE'], $result['diagnostics']['owner_statuses']);
        self::assertSame([], $result['diagnostics']['blocking_owners']);
        self::assertSame(1, $result['diagnostics']['coverage_gaps']['Knowledge']['identity_unresolved_rows']);
        self::assertSame([], $result['reconciliation_candidates']);
    }

    public function test_missing_video_referents_are_coverage_findings_not_clusters(): void
    {
        $result = $this->audit('Knowledge', [
            ['canonical_id' => 'claim-missing-video-1', 'claim_type' => 'provenance', 'claim_text' => 'Video concerns Variant A.', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-a']]],
            ['canonical_id' => 'claim-missing-video-2', 'claim_type' => 'provenance', 'claim_text' => 'Video concerns Variant A.', 'provenance' => ['origin' => 'CAPTURE_VIDEO_SOURCE_PROVENANCE', 'metadata' => ['subject_id' => 'subject-a']]],
        ]);

        self::assertSame([], $result['owners']['Knowledge']['clusters']);
        self::assertSame(2, $result['owners']['Knowledge']['diagnostics']['identity_unresolved_rows']);
        self::assertCount(2, $result['owners']['Knowledge']['diagnostics']['bounded_identity_review_samples']);
        self::assertSame([], $result['reconciliation_candidates']);
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function audit(string $owner, array $items): array
    {
        return $this->coordinator([$owner => new AuditPage($items)])->audit(50, [], true, $owner);
    }

    /** @param array<string,mixed> $readers */
    private function coordinator(array $readers = []): SystemWideDuplicateAuditCoordinator
    {
        return new SystemWideDuplicateAuditCoordinator($readers, null, 'unit-test-cursor-secret');
    }
}

final class AuditPage implements DuplicateAuditPageReader
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(private array $items) {}
    public function page(?string $after, int $limit): array { return ['items' => array_slice($this->items, 0, $limit), 'next_cursor' => null]; }
}

final class BoundaryAuditPage implements DuplicateAuditPageReader
{
    /** @param list<list<array<string,mixed>>> $pages */
    public function __construct(private array $pages) {}

    public function page(?string $after, int $limit): array
    {
        $index = $after === null ? 0 : 1;
        $items = $this->pages[$index] ?? [];
        return ['items' => array_slice($items, 0, $limit), 'next_cursor' => $index === 0 ? 'boundary-1' : null];
    }
}

final class RepeatingBoundedAuditPage implements DuplicateAuditPageReader
{
    public function page(?string $after, int $limit): array
    {
        $items = [];
        for ($i = 1; $i <= $limit; $i++) $items[] = ['canonical_id' => 'row-' . $i, 'stable_key' => 'row-' . $i];
        return ['items' => $items, 'next_cursor' => 'more'];
    }
}
