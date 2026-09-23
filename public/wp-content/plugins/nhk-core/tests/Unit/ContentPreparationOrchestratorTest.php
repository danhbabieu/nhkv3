<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\ContentPreparationResult;
use NHK\Core\Application\Capture\ContentPreparationOrchestrator;
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Domain\Capture\SubjectResolutionPacket;
use PHPUnit\Framework\TestCase;

final class ContentPreparationOrchestratorTest extends TestCase
{
    public function test_explicit_model_uuid_beats_prose_music_mention_and_locks_prepared_packet(): void
    {
        $modelId = '11111111-1111-4111-8111-111111111111';
        $musicId = '22222222-2222-4222-8222-222222222222';
        $resolver = new SubjectResolutionService(static fn (string $value): array => match ($value) {
            $modelId => [['id' => $modelId, 'type' => 'model', 'stable_key' => 'nhk:model:generic', 'name' => 'Generic Model', 'revision' => 3]],
            'Known Music' => [['id' => $musicId, 'type' => 'music', 'stable_key' => 'nhk:music:known', 'name' => 'Known Music', 'revision' => 2]],
            default => [],
        });
        $orchestrator = new ContentPreparationOrchestrator($resolver);

        $result = $orchestrator->prepare(
            ['canonical_uuid' => $modelId, 'subject_hints' => ['Known Music']],
            ['entity_mentions' => ['Known Music']],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame($modelId, $result->subjectResolutionPacket?->canonicalSubjectId);
        self::assertSame('model', $result->subjectResolutionPacket?->entityType);
        self::assertSame([], $result->plan['article_owned_relations'] ?? []);
        self::assertSame('CANDIDATE', $result->gaps['related_entities'][0]['status'] ?? null);
    }

    public function test_ambiguous_primary_subject_is_review_required_without_enrichment(): void
    {
        $enrichmentCalls = 0;
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === 'Ambiguous'
            ? [
                ['id' => '33333333-3333-4333-8333-333333333333', 'type' => 'model', 'name' => 'Model A', 'revision' => 1],
                ['id' => '44444444-4444-4444-8444-444444444444', 'type' => 'model', 'name' => 'Model B', 'revision' => 1],
            ]
            : []);
        $orchestrator = new ContentPreparationOrchestrator($resolver, null, static function () use (&$enrichmentCalls): array {
            $enrichmentCalls++;
            return ['status' => 'APPLIED'];
        });

        $result = $orchestrator->prepare(['subject_hints' => ['Ambiguous']], [], [], [
            'enrichment_requests' => [['locator' => 'Ambiguous', 'evidence_supported' => true]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertContains('PRIMARY_SUBJECT_AMBIGUOUS', $result->reviewReasons);
        self::assertSame(0, $enrichmentCalls);
    }

    public function test_persisted_user_confirmed_subject_wins_over_ambiguous_raw_hint(): void
    {
        $subjectId = '5f6c98ca-869a-4418-a8a4-1a32eb931c5e';
        $resolverCalls = 0;
        $resolver = new SubjectResolutionService(static function (string $value) use (&$resolverCalls): array {
            $resolverCalls++;
            return $value === 'Ambiguous raw hint' ? [
                ['id' => '33333333-3333-4333-8333-333333333333', 'type' => 'variant', 'name' => 'Candidate A', 'revision' => 1],
                ['id' => '44444444-4444-4444-8444-444444444444', 'type' => 'variant', 'name' => 'Candidate B', 'revision' => 1],
            ] : [];
        });
        $packet = new SubjectResolutionPacket('resolved', $subjectId, 'variant', 'nhk:variant:confirmed', 'Confirmed Subject', 7, 'user_confirmed', [], 'USER_CONFIRMED_SUBJECT_RECONCILIATION');

        $result = (new ContentPreparationOrchestrator($resolver))->prepare(
            ['subject_hints' => ['Ambiguous raw hint']],
            [],
            [],
            ['persisted_subject_resolution_packet' => $packet->toArray()],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame($subjectId, $result->subjectResolutionPacket?->canonicalSubjectId);
        self::assertSame(0, $resolverCalls);
        self::assertContains('PERSISTED_RESOLVED_SUBJECT_REUSED', $result->diagnostics['subject_precedence'] ?? []);
    }

    public function test_persisted_auto_resolved_subject_also_beats_weaker_candidates(): void
    {
        $subjectId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $packet = new SubjectResolutionPacket('resolved', $subjectId, 'model', 'nhk:model:auto', 'Auto Subject', 3, 'semantic_auto_resolution', [], 'SEMANTIC_AUTO_RESOLUTION');
        $result = (new ContentPreparationOrchestrator(new SubjectResolutionService(static fn (): array => throw new \LogicException('weaker candidates must not resolve'))))->prepare(
            ['subject_hints' => ['Weaker title candidate']],
            [],
            [],
            ['persisted_subject_resolution_packet' => $packet->toArray()],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame($subjectId, $result->subjectResolutionPacket?->canonicalSubjectId);
    }

    public function test_invalid_or_retired_persisted_subject_reopens_resolution(): void
    {
        $subjectId = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $packet = new SubjectResolutionPacket('resolved', $subjectId, 'variant', 'nhk:variant:retired', 'Retired Subject', 4, 'user_confirmed', [], 'USER_CONFIRMED_SUBJECT_RECONCILIATION');
        $result = (new ContentPreparationOrchestrator(new SubjectResolutionService(static fn (string $value): array => $value === 'Replacement' ? [['id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'type' => 'variant', 'name' => 'Replacement', 'revision' => 5]] : [])))->prepare(
            ['subject_hints' => ['Replacement']], [], [],
            ['persisted_subject_resolution_packet' => $packet->toArray(), 'canonical_subject_readback' => ['status' => 'retired', 'canonical_id' => $subjectId, 'revision' => 4]],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame('cccccccc-cccc-4ccc-8ccc-cccccccccccc', $result->subjectResolutionPacket?->canonicalSubjectId);
        self::assertContains('PERSISTED_SUBJECT_REOPENED', $result->diagnostics['subject_precedence'] ?? []);
    }

    public function test_explicit_higher_authority_reconciliation_replaces_persisted_subject(): void
    {
        $oldId = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
        $newId = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
        $old = new SubjectResolutionPacket('resolved', $oldId, 'model', 'nhk:model:old', 'Old', 2, 'semantic_auto_resolution', [], 'SEMANTIC_AUTO_RESOLUTION');
        $new = new SubjectResolutionPacket('resolved', $newId, 'variant', 'nhk:variant:new', 'New', 6, 'user_confirmed', [], 'USER_CONFIRMED_SUBJECT_RECONCILIATION');
        $result = (new ContentPreparationOrchestrator(new SubjectResolutionService(static fn (): array => throw new \LogicException('explicit reconciliation must not re-resolve'))))->prepare(
            ['subject_hints' => ['conflicting weaker hint']], [], [],
            ['persisted_subject_resolution_packet' => $old->toArray(), 'subject_reconciliation' => ['authority' => 'USER_CONFIRMED_SUBJECT_RECONCILIATION', 'packet' => $new->toArray()]],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame($newId, $result->subjectResolutionPacket?->canonicalSubjectId);
        self::assertContains('EXPLICIT_SUBJECT_RECONCILIATION_APPLIED', $result->diagnostics['subject_precedence'] ?? []);
    }

    public function test_missing_candidate_without_supported_evidence_does_not_create_semantic_truth(): void
    {
        $enrichmentCalls = 0;
        $resolver = new SubjectResolutionService(static fn (string $value): array => []);
        $orchestrator = new ContentPreparationOrchestrator($resolver, null, static function () use (&$enrichmentCalls): array {
            $enrichmentCalls++;
            return ['status' => 'APPLIED'];
        });

        $result = $orchestrator->prepare(['subject_hints' => ['Missing Classification']], [], [], [
            'enrichment_requests' => [[
                'locator' => 'Missing Classification',
                'entity_type' => 'classification',
                'evidence_supported' => false,
            ]],
        ]);

        self::assertSame('REVIEW_REQUIRED', $result->status);
        self::assertContains('INSUFFICIENT_ENRICHMENT_EVIDENCE', $result->reviewReasons);
        self::assertSame(0, $enrichmentCalls);
    }

    public function test_governed_semantic_owner_enrichment_is_read_back_and_re_resolved(): void
    {
        $classificationId = '55555555-5555-4555-8555-555555555555';
        $enrichmentCalls = 0;
        $available = false;
        $resolver = new SubjectResolutionService(static function (string $value) use (&$available, $classificationId): array {
            if ($value === 'Generic Model') return [['id' => '11111111-1111-4111-8111-111111111111', 'type' => 'model', 'name' => 'Generic Model', 'revision' => 3]];
            if ($value === 'Missing Classification' && $available) return [['id' => $classificationId, 'type' => 'classification', 'name' => 'Missing Classification', 'revision' => 2]];
            return [];
        });
        $orchestrator = new ContentPreparationOrchestrator($resolver, null, static function (array $request) use (&$available, &$enrichmentCalls, $classificationId): array {
            $enrichmentCalls++;
            $available = true;
            return [
                'status' => 'APPLIED',
                'canonical_readback' => ['canonical_id' => $classificationId, 'revision' => 2],
                'semantic_owner_relations' => [['predicate' => 'classified_as', 'status' => 'VERIFIED']],
            ];
        });

        $result = $orchestrator->prepare(
            ['subject_hints' => ['Generic Model'], 'canonical_uuid' => '11111111-1111-4111-8111-111111111111'],
            [],
            [],
            ['enrichment_requests' => [[
                'locator' => 'Missing Classification',
                'entity_type' => 'classification',
                'evidence_supported' => true,
                'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
            ]]],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame(1, $enrichmentCalls);
        self::assertSame($classificationId, $result->enrichment['items'][0]['canonical_readback']['canonical_id']);
        self::assertSame('classified_as', $result->plan['semantic_owner_relations'][0]['predicate']);
        self::assertSame('11111111-1111-4111-8111-111111111111', $result->subjectResolutionPacket?->canonicalSubjectId);
    }

    public function test_prose_mention_does_not_create_article_owned_relation(): void
    {
        $modelId = '11111111-1111-4111-8111-111111111111';
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === $modelId
            ? [['id' => $modelId, 'type' => 'model', 'name' => 'Generic Model', 'revision' => 1]]
            : []);
        $orchestrator = new ContentPreparationOrchestrator($resolver);

        $result = $orchestrator->prepare(
            ['canonical_uuid' => $modelId],
            ['entity_mentions' => ['Known Music'], 'relation_hints' => [['target' => 'Known Music']]],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame([], $result->plan['article_owned_relations']);
        self::assertContains('Known Music', array_column($result->gaps['related_entities'], 'value'));
    }

    public function test_media_or_video_input_remains_candidate_context_before_packet_lock(): void
    {
        $modelId = '11111111-1111-4111-8111-111111111111';
        $resolver = new SubjectResolutionService(static fn (string $value): array => $value === $modelId
            ? [['id' => $modelId, 'type' => 'model', 'name' => 'Generic Model', 'revision' => 1]]
            : []);
        $orchestrator = new ContentPreparationOrchestrator($resolver);

        $result = $orchestrator->prepare(
            ['canonical_uuid' => $modelId, 'video' => ['external_id' => 'video-1'], 'assets' => [['media_id' => 'media-1']]],
            ['entity_mentions' => ['Unknown Model']],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame('candidate_input', $result->diagnostics['media_video']['status']);
        self::assertSame([], $result->plan['article_owned_relations']);
    }

    public function test_preparation_plan_excludes_unrelated_canonical_inventory_candidates(): void
    {
        $modelId = '11111111-1111-4111-8111-111111111111';
        $musicId = '22222222-2222-4222-8222-222222222222';
        $resolver = new SubjectResolutionService(static fn (string $value): array => match ($value) {
            $modelId => [['id' => $modelId, 'type' => 'model', 'name' => 'Model A', 'revision' => 1]],
            'Music B' => [['id' => $musicId, 'type' => 'music', 'name' => 'Music B', 'revision' => 1]],
            default => [],
        });
        $orchestrator = new ContentPreparationOrchestrator($resolver, static fn (): array => [
            'status' => 'available',
            'candidates' => [
                ['id' => 'video-c', 'type' => 'video', 'name' => 'Variant C Video'],
                ['id' => 'unrelated', 'type' => 'brand', 'name' => 'Unrelated Brand'],
                ['id' => $musicId, 'type' => 'music', 'name' => 'Music B'],
            ],
        ]);

        $result = $orchestrator->prepare(
            ['canonical_uuid' => $modelId],
            ['entity_mentions' => ['Music B']],
        );

        self::assertSame('PREPARED', $result->status);
        self::assertSame(['Music B'], array_values(array_map(
            static fn (array $entity): string => (string) ($entity['name'] ?? $entity['value'] ?? ''),
            $result->plan['related_entities'],
        )));
    }

    public function test_second_capture_reuses_newly_available_canonical_candidate_without_duplicate_enrichment(): void
    {
        $available = false;
        $enrichmentCalls = 0;
        $classificationId = '55555555-5555-4555-8555-555555555555';
        $resolver = new SubjectResolutionService(static function (string $value) use (&$available, $classificationId): array {
            if ($value === 'Generic Model') return [['id' => '11111111-1111-4111-8111-111111111111', 'type' => 'model', 'name' => 'Generic Model', 'revision' => 1]];
            if ($value === 'New Classification' && $available) return [['id' => $classificationId, 'type' => 'classification', 'name' => 'New Classification', 'revision' => 2]];
            return [];
        });
        $orchestrator = new ContentPreparationOrchestrator($resolver, null, static function () use (&$available, &$enrichmentCalls, $classificationId): array {
            ++$enrichmentCalls;
            $available = true;
            return ['status' => 'APPLIED', 'canonical_readback' => ['canonical_id' => $classificationId, 'revision' => 2]];
        });
        $input = ['canonical_uuid' => '11111111-1111-4111-8111-111111111111', 'subject_hints' => ['Generic Model']];
        $context = ['enrichment_requests' => [['locator' => 'New Classification', 'evidence_supported' => true]]];

        $first = $orchestrator->prepare($input, [], [], $context);
        $second = $orchestrator->prepare($input, [], [], $context);

        self::assertSame('PREPARED', $first->status);
        self::assertSame('PREPARED', $second->status);
        self::assertSame(1, $enrichmentCalls);
        self::assertSame('REUSED', $second->enrichment['items'][0]['result']['status']);
    }

    public function test_prepared_result_serializes_packet_without_raw_input(): void
    {
        $packet = new SubjectResolutionPacket(
            'resolved',
            '11111111-1111-4111-8111-111111111111',
            'model',
            'nhk:model:generic',
            'Generic Model',
            3,
            'explicit_uuid',
        );

        $result = new ContentPreparationResult(
            'PREPARED',
            hash('sha256', 'generic-input'),
            $packet,
            [['value' => 'Generic Model', 'source' => 'explicit_uuid']],
            ['primary_subject' => 'EXISTING'],
            ['relations' => []],
            ['status' => 'VERIFIED', 'canonical_readback' => ['revision' => 3]],
            ['phase' => 'LOCK_FINAL_SUBJECT_PACKET'],
            [],
            [],
        );

        $serialized = $result->toArray();

        self::assertSame('PREPARED', $serialized['status']);
        self::assertSame(hash('sha256', 'generic-input'), $serialized['preparation_fingerprint']);
        self::assertSame($packet->toArray(), $serialized['subject_resolution_packet']);
        self::assertArrayNotHasKey('raw_input', $serialized);
        self::assertArrayNotHasKey('content', $serialized);
    }

    public function test_result_rejects_unknown_workflow_status(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ContentPreparationResult('COMPLETE', hash('sha256', 'generic-input'));
    }

    public function test_prepared_result_rehydrates_from_durable_capture_array(): void
    {
        $packet = new SubjectResolutionPacket(
            'resolved',
            '11111111-1111-4111-8111-111111111111',
            'model',
            'nhk:model:generic',
            'Generic Model',
            3,
            'explicit_uuid',
        );
        $original = new ContentPreparationResult('PREPARED', hash('sha256', 'generic-input'), $packet, [], [], [], ['status' => 'VERIFIED']);

        $rehydrated = ContentPreparationResult::fromArray($original->toArray());

        self::assertNotNull($rehydrated);
        self::assertSame('PREPARED', $rehydrated?->status);
        self::assertSame($packet->toArray(), $rehydrated?->subjectResolutionPacket?->toArray());
    }
}
