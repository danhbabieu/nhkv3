<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Capture\{CaptureEnrichmentPlanningEnvelope, CaptureOwnerOutcome, CaptureOwnerDag};
use PHPUnit\Framework\TestCase;

final class CaptureEnrichmentPlanningTest extends TestCase
{
    /** @dataProvider inputSeams */
    public function test_envelope_is_source_agnostic_for_text_image_video_and_mixed_inputs(array $input, array $assets): void
    {
        $envelope = CaptureEnrichmentPlanningEnvelope::fromState(
            'capture-' . ($input['kind'] ?? 'generic'),
            hash('sha256', json_encode($input)),
            $input,
            ['source_context' => ['source_kind' => $input['kind'] ?? 'generic']],
            $assets,
            ['content_intent' => ['intent' => $input['intent'] ?? 'KNOWLEDGE_DELTA']],
        );

        self::assertSame(CaptureEnrichmentPlanningEnvelope::VERSION, $envelope->version);
        self::assertNotSame('', $envelope->fingerprint());
        self::assertArrayHasKey('lexical', $envelope->ownerTracks);
        self::assertArrayHasKey('video', $envelope->ownerTracks);
    }

    public static function inputSeams(): iterable
    {
        yield 'text-only' => [['kind' => 'text', 'intent' => 'KNOWLEDGE_DELTA'], []];
        yield 'image-only' => [['kind' => 'image', 'intent' => 'MEDIA_ENRICHMENT'], [['kind' => 'image', 'media_id' => 'media-1']]];
        yield 'video-only' => [['kind' => 'video', 'intent' => 'VIDEO'], [['kind' => 'video', 'video_id' => 'video-1']]];
        yield 'mixed' => [['kind' => 'mixed', 'intent' => 'IMAGE_ARTICLE'], [['kind' => 'image', 'media_id' => 'media-1'], ['kind' => 'video', 'video_id' => 'video-1']]];
    }

    public function test_envelope_fingerprint_is_deterministic_and_binds_owner_plans_and_dependencies(): void
    {
        $input = [
            'capture_id' => 'capture-1',
            'request_fingerprint' => hash('sha256', 'request'),
            'source_manifest' => [['kind' => 'text', 'id' => 'source-1']],
            'interpretation_fingerprint' => hash('sha256', 'interpretation'),
            'owner_tracks' => [
                'lexical' => ['status' => 'READ_BACK_VERIFIED', 'plan_fingerprint' => 'lexical-plan', 'expected_revision' => 2],
                'knowledge' => ['status' => 'BLOCKED', 'depends_on' => ['authority'], 'dependency_revisions' => ['authority' => 4]],
            ],
            'selected_candidates' => [['id' => 'candidate-1']],
        ];

        $left = CaptureEnrichmentPlanningEnvelope::fromArray($input);
        $right = CaptureEnrichmentPlanningEnvelope::fromArray(array_reverse($input, true));

        self::assertSame($left->fingerprint(), $right->fingerprint());
        self::assertSame('capture-enrichment-envelope-1', $left->version);
        self::assertSame('READ_BACK_VERIFIED', $left->ownerTracks['lexical']['status']);
        self::assertSame(['authority'], $left->ownerTracks['knowledge']['depends_on']);
        self::assertArrayHasKey('knowledge', $left->casBindings);
    }

    public function test_owner_outcome_normalizes_domain_results_without_replacing_domain_lifecycle(): void
    {
        $outcome = CaptureOwnerOutcome::fromDomainResult('dictionary', [
            'status' => 'REUSED_VERIFIED',
            'canonical_id' => 'sense-1',
            'canonical_readback' => ['canonical_id' => 'sense-1', 'revision' => 3],
            'plan_fingerprint' => 'plan-1',
            'diagnostics' => ['source' => 'mention'],
        ]);

        self::assertSame('lexical', $outcome->owner);
        self::assertSame('READ_BACK_VERIFIED', $outcome->status);
        self::assertSame('sense-1', $outcome->canonicalReadback['canonical_id']);
        self::assertTrue($outcome->isSuccessful());
        self::assertSame('REUSED_VERIFIED', $outcome->domainStatus);
    }

    public function test_dag_keeps_successful_lexical_track_when_authority_blocks_downstream_tracks(): void
    {
        $dag = new CaptureOwnerDag([
            'lexical' => ['depends_on' => []],
            'authority' => ['depends_on' => []],
            'knowledge' => ['depends_on' => ['authority']],
            'relations' => ['depends_on' => ['authority', 'knowledge']],
        ]);
        $outcomes = [
            'lexical' => CaptureOwnerOutcome::fromArray(['owner' => 'lexical', 'status' => 'READ_BACK_VERIFIED']),
            'authority' => CaptureOwnerOutcome::fromArray(['owner' => 'authority', 'status' => 'REVIEW_REQUIRED']),
        ];

        self::assertSame([], $dag->retryableTracks($outcomes));
        self::assertSame('BLOCKED_ON_DEPENDENCY', $dag->statusFor('knowledge', $outcomes));
        self::assertSame(['authority', 'knowledge'], $dag->dependencyClosure('relations'));
    }

    public function test_changed_dependency_revision_requires_replan_and_does_not_reuse_successful_receipt(): void
    {
        $dag = new CaptureOwnerDag(['knowledge' => ['depends_on' => ['authority']]]);
        $outcomes = [
            'authority' => CaptureOwnerOutcome::fromArray(['owner' => 'authority', 'status' => 'READ_BACK_VERIFIED', 'readback_revision' => 5]),
            'knowledge' => CaptureOwnerOutcome::fromArray(['owner' => 'knowledge', 'status' => 'READ_BACK_VERIFIED', 'dependency_revisions' => ['authority' => 4]]),
        ];

        self::assertSame('REPLAN_REQUIRED', $dag->statusFor('knowledge', $outcomes));
        self::assertSame(['knowledge'], $dag->retryableTracks($outcomes));
    }

    public function test_dependency_cycles_fail_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CaptureOwnerDag([
            'authority' => ['depends_on' => ['knowledge']],
            'knowledge' => ['depends_on' => ['authority']],
        ]);
    }
}
