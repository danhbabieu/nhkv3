<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Completion\CompletionCoordinator;
use NHK\Core\Application\Capture\EditorialCaptureCoordinator;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class CompletionConvergenceTest extends TestCase
{
    public function test_proposal_apply_is_not_public_completion(): void
    {
        $packet = (new CompletionCoordinator())->finalize('video', 'video-1', [
            'proposal_state' => 'applied',
            'canonical_readback' => ['canonical_id' => 'video-1'],
        ]);

        self::assertSame('applied', $packet['proposal_state']);
        self::assertSame('COMPLETE', $packet['canonical_state']);
        self::assertSame('BLOCKED', $packet['public_state']);
        self::assertSame('BLOCKED', $packet['frontend_state']);
        self::assertFalse($packet['complete']);
    }

    public function test_public_video_is_complete_only_after_all_readbacks(): void
    {
        $packet = (new CompletionCoordinator())->finalize('video', 'video-1', [
            'proposal_state' => 'applied',
            'canonical_readback' => ['canonical_id' => 'video-1'],
            'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'COMPLETE',
            'content_quality' => 'CONTENT_COMPLETE',
            'public_eligible' => true,
            'frontend_verified' => true,
        ]);

        self::assertTrue($packet['complete']);
        self::assertSame('READY', $packet['public_state']);
        self::assertSame('VERIFIED', $packet['frontend_state']);
        self::assertSame([], $packet['blockers']);
    }

    public function test_video_completion_requires_content_complete_in_addition_to_public_readbacks(): void
    {
        $packet = (new CompletionCoordinator())->finalize('video', 'video-1', [
            'canonical_readback' => ['canonical_id' => 'video-1'],
            'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'COMPLETE',
            'content_quality' => 'CONTENT_NEEDS_REVIEW',
            'public_eligible' => true,
            'frontend_verified' => true,
        ]);

        self::assertFalse($packet['complete']);
        self::assertSame('CONTENT_NEEDS_REVIEW', $packet['content_state']);
        self::assertContains('CONTENT_NEEDS_REVIEW', $packet['blockers']);
    }

    public function test_source_and_evidence_can_complete_without_public_route(): void
    {
        foreach (['source', 'evidence'] as $type) {
            $packet = (new CompletionCoordinator())->finalize($type, $type . '-1', [
                'canonical_readback' => ['canonical_id' => $type . '-1'],
            ]);
            self::assertTrue($packet['complete']);
            self::assertSame('NOT_APPLICABLE', $packet['public_state']);
            self::assertSame('NOT_APPLICABLE', $packet['frontend_state']);
        }
    }

    public function test_authority_can_be_canonical_complete_but_public_incomplete(): void
    {
        $packet = (new CompletionCoordinator())->finalize('brand', 'brand-1', [
            'canonical_readback' => ['canonical_id' => 'brand-1'],
            'public_eligible' => false,
        ]);

        self::assertSame('COMPLETE', $packet['canonical_state']);
        self::assertSame('BLOCKED', $packet['public_state']);
        self::assertFalse($packet['complete']);
    }

    public function test_model_operation_with_required_relation_unresolved_is_partial(): void
    {
        $packet = (new CompletionCoordinator())->finalize('model', 'model-1', [
            'canonical_readback' => ['canonical_id' => 'model-1'],
            'relation_or_usage_state' => 'PARTIAL',
            'public_eligible' => true,
            'frontend_verified' => true,
            'blockers' => ['MODEL_OF_RELATION_READBACK_REQUIRED'],
        ]);

        self::assertSame('COMPLETE', $packet['canonical_state']);
        self::assertSame('PARTIAL', $packet['relation_or_usage_state']);
        self::assertFalse($packet['complete']);
    }

    public function test_media_attachment_without_public_derivative_or_usage_is_partial(): void
    {
        $packet = (new CompletionCoordinator())->finalize('media', 'media-1', [
            'canonical_readback' => ['canonical_id' => 'media-1'],
            'dependency_state' => 'PARTIAL',
            'relation_or_usage_state' => 'PARTIAL',
            'public_eligible' => false,
            'frontend_verified' => false,
            'blockers' => ['MEDIA_PUBLIC_DERIVATIVE_REQUIRED', 'MEDIAUSAGE_INCOMPLETE'],
        ]);

        self::assertSame('PARTIAL', $packet['dependency_state']);
        self::assertContains('MEDIAUSAGE_INCOMPLETE', $packet['blockers']);
        self::assertFalse($packet['complete']);
    }

    public function test_capture_aggregates_mixed_children_as_partial(): void
    {
        $packet = (new CompletionCoordinator())->aggregateCapture('capture-1', [
            ['owner_type' => 'wp_post', 'owner_id' => '450', 'canonical_readback' => ['id' => 450], 'public_eligible' => true, 'frontend_verified' => true],
            ['owner_type' => 'knowledge', 'owner_id' => 'claim-1', 'canonical_readback' => ['canonical_id' => 'claim-1'], 'public_eligible' => true, 'frontend_verified' => true],
            ['owner_type' => 'video', 'owner_id' => 'video-1', 'canonical_readback' => ['canonical_id' => 'video-1'], 'blockers' => ['VIDEO_FRONTEND_READBACK_FAILED']],
        ]);

        self::assertSame('BLOCKED', $packet['status']);
        self::assertFalse($packet['complete']);
        self::assertContains('VIDEO_FRONTEND_READBACK_FAILED', $packet['blockers']);
        self::assertContains('CANONICAL_READBACK_UNVERIFIED', $packet['blockers']);
        self::assertCount(3, $packet['children']);
    }

    public function test_current_owner_projection_supersedes_historical_projection_by_identity(): void
    {
        $packet = (new CompletionCoordinator())->aggregateCapture('capture-1', [
            ['completion' => ['owner_type' => 'video', 'owner_id' => 'video-1', 'status' => 'PARTIAL', 'complete' => false, 'blockers' => ['CONTENT_NEEDS_REVIEW']]],
            ['completion' => ['owner_type' => 'video', 'owner_id' => 'video-1', 'status' => 'COMPLETE', 'complete' => true, 'canonical_state' => 'COMPLETE', 'canonical_readback_verified' => true, 'content_state' => 'CONTENT_COMPLETE', 'public_state' => 'READY', 'frontend_state' => 'VERIFIED', 'blockers' => []]],
        ], ['canonical_state' => 'COMPLETE', 'canonical_readback' => ['canonical_id' => 'capture-1'], 'required_owners' => [['owner_type' => 'video', 'owner_id' => 'video-1']]]);

        self::assertCount(1, $packet['children']);
        self::assertSame('COMPLETE', $packet['children'][0]['status']);
        self::assertTrue($packet['complete']);
        self::assertNotContains('CONTENT_NEEDS_REVIEW', $packet['blockers']);
    }

    public function test_current_owner_failure_supersedes_historical_complete_projection(): void
    {
        $packet = (new CompletionCoordinator())->aggregateCapture('capture-1', [
            ['owner_type' => 'video', 'owner_id' => 'video-1', 'status' => 'COMPLETE', 'complete' => true, 'canonical_readback' => ['canonical_id' => 'video-1'], 'content_quality' => 'CONTENT_COMPLETE', 'public_eligible' => true, 'frontend_verified' => true],
            ['completion' => ['owner_type' => 'video', 'owner_id' => 'video-1', 'status' => 'PARTIAL', 'complete' => false, 'blockers' => ['FRONTEND_READBACK_NOT_VERIFIED']]],
        ], ['canonical_state' => 'COMPLETE', 'canonical_readback' => ['canonical_id' => 'capture-1'], 'required_owners' => [['owner_type' => 'video', 'owner_id' => 'video-1']]]);

        self::assertCount(1, $packet['children']);
        self::assertSame('PARTIAL', $packet['children'][0]['status']);
        self::assertFalse($packet['complete']);
        self::assertContains('FRONTEND_READBACK_NOT_VERIFIED', $packet['blockers']);
    }

    public function test_semantic_video_dependencies_do_not_require_public_frontend_routes(): void
    {
        $packet = (new CompletionCoordinator())->finalize('knowledge', 'claim-1', [
            'canonical_readback' => ['canonical_id' => 'claim-1'],
            'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'COMPLETE',
            'owner_role' => 'semantic_dependency',
            'public_projection_owner' => false,
        ]);

        self::assertTrue($packet['complete']);
        self::assertSame('NOT_APPLICABLE', $packet['public_state']);
        self::assertSame('NOT_APPLICABLE', $packet['frontend_state']);
    }

    public function test_public_semantic_dependency_uses_internal_completion_role_not_public_projection(): void
    {
        $packet = (new CompletionCoordinator())->finalize('knowledge', 'claim-public-1', [
            'canonical_readback' => ['canonical_id' => 'claim-public-1'],
            'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'COMPLETE',
            'owner_role' => 'semantic_dependency',
            'public_projection_owner' => false,
            'public_eligible' => true,
            'frontend_verified' => true,
        ]);

        self::assertTrue($packet['complete']);
        self::assertSame('NOT_APPLICABLE', $packet['public_state']);
        self::assertSame('NOT_APPLICABLE', $packet['frontend_state']);
    }

    public function test_real_semantic_dependency_failure_still_blocks_completion(): void
    {
        $packet = (new CompletionCoordinator())->finalize('evidence', 'evidence-1', [
            'canonical_readback' => ['canonical_id' => 'evidence-1'],
            'dependency_state' => 'PARTIAL',
            'relation_or_usage_state' => 'COMPLETE',
            'owner_role' => 'semantic_dependency',
            'public_projection_owner' => false,
            'blockers' => ['EVIDENCE_READBACK_INVALID'],
        ]);

        self::assertFalse($packet['complete']);
        self::assertContains('EVIDENCE_READBACK_INVALID', $packet['blockers']);
        self::assertSame('NOT_APPLICABLE', $packet['public_state']);
    }

    public function test_relation_or_usage_can_be_not_applicable_without_inventing_an_edge(): void
    {
        $packet = (new CompletionCoordinator())->finalize('knowledge', 'claim-1', [
            'canonical_readback' => ['canonical_id' => 'claim-1'],
            'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'NOT_APPLICABLE',
            'public_eligible' => true,
            'frontend_verified' => true,
        ]);

        self::assertTrue($packet['complete']);
        self::assertSame('NOT_APPLICABLE', $packet['relation_or_usage_state']);
    }

    public function test_explicit_canonical_complete_without_owner_readback_is_not_complete(): void
    {
        $packet = (new CompletionCoordinator())->finalize('video', 'video-1', [
            'canonical_state' => 'COMPLETE',
            'dependency_state' => 'COMPLETE',
            'relation_or_usage_state' => 'NOT_APPLICABLE',
            'content_quality' => 'CONTENT_COMPLETE',
            'public_eligible' => true,
            'frontend_verified' => true,
        ]);

        self::assertFalse($packet['complete']);
        self::assertSame('BLOCKED', $packet['canonical_state']);
        self::assertContains('CANONICAL_READBACK_UNVERIFIED', $packet['blockers']);
    }

    public function test_capture_required_owner_branch_is_reported_when_readback_is_missing(): void
    {
        $packet = (new CompletionCoordinator())->aggregateCapture('capture-1', [
            [
                'owner_type' => 'wp_post',
                'owner_id' => '450',
                'canonical_readback' => ['id' => 450],
                'public_eligible' => true,
                'frontend_verified' => true,
            ],
        ], [
            'required_owners' => [
                ['owner_type' => 'wp_post', 'owner_id' => '450'],
                ['owner_type' => 'video'],
            ],
        ]);

        self::assertFalse($packet['complete']);
        self::assertSame([['owner_type' => 'video', 'owner_id' => '']], $packet['missing_required_owners']);
        self::assertContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $packet['blockers']);
        self::assertContains('video', $packet['resume_hints']['resume_children']);
    }

    public function test_capture_cannot_be_complete_without_capture_canonical_readback(): void
    {
        $packet = (new CompletionCoordinator())->aggregateCapture('capture-1', [
            ['owner_type' => 'knowledge', 'owner_id' => 'claim-1', 'canonical_readback' => ['canonical_id' => 'claim-1']],
        ]);

        self::assertFalse($packet['complete']);
        self::assertSame('BLOCKED', $packet['canonical_state']);
        self::assertContains('CANONICAL_READBACK_UNVERIFIED', $packet['blockers']);
    }

    public function test_empty_required_owner_id_never_matches_a_verified_child(): void
    {
        $packet = (new CompletionCoordinator())->aggregateCapture('capture-1', [
            ['owner_type' => 'media', 'owner_id' => 'media-1', 'canonical_readback' => ['canonical_id' => 'media-1']],
        ], [
            'required_owners' => [['owner_type' => 'media', 'owner_id' => '']],
        ]);

        self::assertFalse($packet['complete']);
        self::assertSame([['owner_type' => 'media', 'owner_id' => '']], $packet['missing_required_owners']);
        self::assertContains('REQUIRED_OWNER_READBACK_UNVERIFIED', $packet['blockers']);
    }

    public function test_video_required_owner_reuses_canonical_id_from_governed_writeback(): void
    {
        $coordinator = (new \ReflectionClass(EditorialCaptureCoordinator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($coordinator, 'requiredOwners');
        $method->setAccessible(true);
        $capture = new CaptureRecord(
            '01a0b384-6a83-7f99-b231-d784b9ab9542',
            'capture-1',
            hash('sha256', 'fingerprint'),
            'SEMANTICS_RECONCILED',
            'IN_PROGRESS',
        );
        $owners = $method->invoke($coordinator, ['intent' => 'VIDEO'], $capture, [], [], [], [
            'writes' => [[
                'entity_type' => 'video',
                'canonical_id' => 'video-1',
                'canonical_readback' => ['canonical_id' => 'video-1', 'revision' => 2],
            ]],
        ]);

        self::assertSame([['owner_type' => 'video', 'owner_id' => 'video-1']], $owners);
    }

    public function test_media_enrichment_required_owners_are_all_reconciled_media_ids_deduplicated(): void
    {
        $coordinator = (new \ReflectionClass(EditorialCaptureCoordinator::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod($coordinator, 'requiredOwners');
        $method->setAccessible(true);
        $capture = new CaptureRecord(
            '01a0b384-6a83-7f99-b231-d784b9ab9542',
            'capture-1',
            hash('sha256', 'fingerprint'),
            'MEDIA_RECONCILED',
            'IN_PROGRESS',
        );

        $owners = $method->invoke($coordinator, ['intent' => 'MEDIA_ENRICHMENT'], $capture, [], [
            'status' => 'COMPLETE',
            'media_ids' => ['media-1', 'media-2', 'media-1'],
            'bindings' => [[
                'readback' => ['status' => 'verified', 'media_id' => 'media-2'],
            ]],
        ], [], []);

        self::assertSame([
            ['owner_type' => 'media', 'owner_id' => 'media-1'],
            ['owner_type' => 'media', 'owner_id' => 'media-2'],
        ], $owners);
    }
}
