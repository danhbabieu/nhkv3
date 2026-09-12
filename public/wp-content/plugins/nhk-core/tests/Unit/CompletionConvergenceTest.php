<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Completion\CompletionCoordinator;
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
            'public_eligible' => true,
            'frontend_verified' => true,
        ]);

        self::assertTrue($packet['complete']);
        self::assertSame('READY', $packet['public_state']);
        self::assertSame('VERIFIED', $packet['frontend_state']);
        self::assertSame([], $packet['blockers']);
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

        self::assertSame('PARTIAL', $packet['status']);
        self::assertFalse($packet['complete']);
        self::assertContains('VIDEO_FRONTEND_READBACK_FAILED', $packet['blockers']);
        self::assertCount(3, $packet['children']);
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
}
