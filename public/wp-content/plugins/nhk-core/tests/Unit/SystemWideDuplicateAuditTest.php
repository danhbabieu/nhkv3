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
        $result = (new SystemWideDuplicateAuditCoordinator(['Authority' => $reader]))->audit(1);
        self::assertSame('PARTIAL', $result['owners']['Authority']['status']);
        self::assertSame('a-1', $result['owners']['Authority']['next_cursor']);
        self::assertSame('a-1', $result['owners']['Authority']['cursor_page_provenance']['next_cursor']);
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
            ['canonical_id' => 'p-1', 'subject_ids' => ['a'], 'intent' => 'history', 'content_kind' => 'TEXT_ARTICLE'],
            ['canonical_id' => 'p-2', 'subject_ids' => ['a'], 'intent' => 'history', 'content_kind' => 'CONTINUATION'],
        ]);
        self::assertSame('LEGITIMATE_DISTINCT', $result['owners']['Article']['clusters'][0]['classification']);
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
    }

    /** @param list<array<string,mixed>> $items @return array<string,mixed> */
    private function audit(string $owner, array $items): array
    {
        return (new SystemWideDuplicateAuditCoordinator([$owner => new AuditPage($items)]))->audit(50);
    }
}

final class AuditPage implements DuplicateAuditPageReader
{
    /** @param list<array<string,mixed>> $items */
    public function __construct(private array $items) {}
    public function page(?string $after, int $limit): array { return ['items' => array_slice($this->items, 0, $limit), 'next_cursor' => null]; }
}
