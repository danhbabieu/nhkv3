<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Capture\{CaptureEnrichmentPlanningEnvelope, CaptureOwnerOutcome, CaptureOwnerDag};
use NHK\Core\Domain\Dictionary\DictionaryPreCreateResolution;
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

    public function test_resolved_subject_packet_is_a_successful_authority_owner_track(): void
    {
        $envelope = CaptureEnrichmentPlanningEnvelope::fromState(
            'capture-resolved-authority',
            hash('sha256', 'resolved-authority'),
            ['intent' => 'KNOWLEDGE_DELTA'],
            [],
            [],
            [
                'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
                'subjects' => [
                    'status' => 'resolved',
                    'primary' => ['id' => 'music-1', 'type' => 'music', 'revision' => 24],
                ],
                'completion' => ['blockers' => []],
            ],
        );

        self::assertSame('READ_BACK_VERIFIED', $envelope->ownerTracks['authority']['status']);
        self::assertSame('music-1', $envelope->ownerTracks['authority']['canonical_readback']['canonical_id']);
        self::assertSame(24, $envelope->ownerTracks['authority']['canonical_readback']['revision']);
        self::assertNotContains('SUBJECT_NOT_FOUND', $envelope->blockers);
    }

    public function test_unresolved_subject_packet_retains_retryable_authority_failure(): void
    {
        $envelope = CaptureEnrichmentPlanningEnvelope::fromState(
            'capture-unresolved-authority',
            hash('sha256', 'unresolved-authority'),
            ['intent' => 'KNOWLEDGE_DELTA'],
            [],
            [],
            [
                'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
                'subjects' => [
                    'status' => 'unresolved',
                    'diagnostics' => ['codes' => ['SUBJECT_NOT_FOUND']],
                ],
            ],
        );

        self::assertSame('FAILED_RETRYABLE', $envelope->ownerTracks['authority']['status']);
        self::assertSame(['codes' => ['SUBJECT_NOT_FOUND']], $envelope->ownerTracks['authority']['diagnostics']);
    }

    public function test_authority_resolution_does_not_hide_existing_failure_history(): void
    {
        $history = ['code' => 'SUBJECT_NOT_FOUND', 'reason' => 'SUPERSEDED_BY_LATEST_PHASE_OUTCOME'];
        $envelope = CaptureEnrichmentPlanningEnvelope::fromState(
            'capture-authority-history',
            hash('sha256', 'authority-history'),
            ['intent' => 'KNOWLEDGE_DELTA'],
            [],
            [],
            [
                'content_intent' => ['intent' => 'KNOWLEDGE_DELTA'],
                'subjects' => [
                    'status' => 'resolved',
                    'primary' => ['id' => 'music-1', 'type' => 'music', 'revision' => 24],
                    'diagnostics' => ['failure_history' => [$history]],
                ],
            ],
        );

        self::assertSame('READ_BACK_VERIFIED', $envelope->ownerTracks['authority']['status']);
        self::assertSame([$history], $envelope->ownerTracks['authority']['diagnostics']['failure_history']);
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

    public function test_requested_retry_selects_only_minimal_dependency_closure_and_reuses_verified_phases(): void
    {
        $dag = new CaptureOwnerDag([
            'interpreted' => ['depends_on' => []],
            'content_preparation' => ['depends_on' => ['interpreted']],
            'video' => ['depends_on' => []],
        ]);
        $outcomes = [
            'interpreted' => CaptureOwnerOutcome::fromArray(['owner' => 'interpreted', 'status' => 'READ_BACK_VERIFIED']),
            'content_preparation' => CaptureOwnerOutcome::fromArray(['owner' => 'content_preparation', 'status' => 'READ_BACK_VERIFIED']),
            'video' => CaptureOwnerOutcome::fromArray(['owner' => 'video', 'status' => 'FAILED_RETRYABLE']),
        ];

        self::assertSame(['video'], $dag->executionPlan(['video'], $outcomes));
    }

    public function test_changed_dependency_revision_replans_only_affected_closure(): void
    {
        $dag = new CaptureOwnerDag([
            'interpreted' => ['depends_on' => []],
            'content_preparation' => ['depends_on' => ['interpreted']],
            'video' => ['depends_on' => ['content_preparation']],
        ]);
        $outcomes = [
            'interpreted' => CaptureOwnerOutcome::fromArray(['owner' => 'interpreted', 'status' => 'READ_BACK_VERIFIED', 'readback_revision' => 4]),
            'content_preparation' => CaptureOwnerOutcome::fromArray(['owner' => 'content_preparation', 'status' => 'READ_BACK_VERIFIED', 'dependency_revisions' => ['interpreted' => 3]]),
            'video' => CaptureOwnerOutcome::fromArray(['owner' => 'video', 'status' => 'FAILED_RETRYABLE']),
        ];

        self::assertSame(['interpreted', 'content_preparation', 'video'], $dag->executionPlan(['video'], $outcomes));
    }

    public function test_dictionary_precreate_packet_round_trips_inside_lexical_owner_track(): void
    {
        $resolution = DictionaryPreCreateResolution::fromDecision(DictionaryPreCreateResolution::CREATE_NEW, 'kính rào', ['domain' => 'clock'], [], [], ['reason' => 'NO_APPLICABLE_CANDIDATE']);
        $envelope = CaptureEnrichmentPlanningEnvelope::fromState(
            'capture-dictionary-envelope',
            hash('sha256', 'request'),
            [],
            [],
            [],
            ['dictionary_observation' => ['status' => 'observed'], 'dictionary_pre_create_resolution' => $resolution->toArray()],
        );

        self::assertSame($resolution->fingerprint(), $envelope->ownerTracks['lexical']['pre_create_resolution']['fingerprint']);
        self::assertNotSame('', $envelope->fingerprint());
    }
}
