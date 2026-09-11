<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureVideoProvenancePlanner;
use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class CaptureVideoProvenancePlannerTest extends TestCase
{
    private const VARIANT = '11111111-1111-4111-8111-111111111111';

    public function test_new_video_gets_governed_source_claim_evidence_chain_before_about_attachment(): void
    {
        $planner = new CaptureVideoProvenancePlanner();

        $plan = $planner->plan(
            'capture-a',
            [
                'operation' => 'ingest',
                'entity_type' => 'video',
                'payload' => [
                    'url' => 'https://www.youtube.com/watch?v=abcdefghijk',
                    'metadata' => [
                        'source' => ['platform' => 'youtube', 'external_video_id' => 'abcdefghijk'],
                        'semantic_attachments' => [[
                            'target_type' => 'variant',
                            'target_uuid' => self::VARIANT,
                            'predicate' => 'about',
                            'evidence_refs' => [['evidence_id' => 'old-source-specific-evidence']],
                        ]],
                    ],
                ],
            ],
            [
                'platform' => 'youtube',
                'external_video_id' => 'abcdefghijk',
                'canonical_source_url' => 'https://www.youtube.com/watch?v=abcdefghijk',
                'source_title' => 'Variant A – tốt nhất – zin tuyệt đối – cực hay',
            ],
            [
                'id' => self::VARIANT,
                'type' => 'variant',
                'name' => 'Variant A',
                'aliases' => ['A'],
            ],
        );

        self::assertSame('READY', $plan['status']);
        self::assertSame([], $plan['video_proposal']['payload']['metadata']['semantic_attachments']);
        self::assertCount(2, $plan['dependencies']);
        self::assertSame(['source', 'knowledge'], array_column($plan['dependencies'], 'entity_type'));
        self::assertSame('provenance', $plan['dependencies'][1]['payload']['claim_type']);
        self::assertStringContainsString('canonical Variant A', $plan['dependencies'][1]['payload']['text']);
        self::assertStringNotContainsString('tốt nhất', $plan['dependencies'][1]['payload']['text']);
        self::assertStringNotContainsString('zin tuyệt đối', $plan['dependencies'][1]['payload']['text']);
        self::assertSame([], $plan['unsupported_classifications']);

        $completed = $planner->attachEvidence($plan, 'source-uuid', 'claim-uuid', 'evidence-uuid');

        self::assertSame('source-uuid', $completed['dependencies'][2]['payload']['source_id']);
        self::assertSame('claim-uuid', $completed['dependencies'][2]['payload']['claim_id']);
        self::assertSame(
            [['target_type' => 'variant', 'target_uuid' => self::VARIANT, 'predicate' => 'about', 'evidence_refs' => [['evidence_id' => 'evidence-uuid']]]],
            $completed['video_proposal']['payload']['metadata']['semantic_attachments'],
        );
    }

    public function test_source_identity_and_evidence_plan_are_not_reused_by_target_variant_only(): void
    {
        $planner = new CaptureVideoProvenancePlanner();
        $subject = ['id' => self::VARIANT, 'type' => 'variant', 'name' => 'Variant A'];

        $old = $planner->plan('capture-old', $this->videoProposal('oldoldold11'), $this->snapshot('oldoldold11'), $subject);
        $new = $planner->plan('capture-new', $this->videoProposal('newnewnew11'), $this->snapshot('newnewnew11'), $subject);

        self::assertNotSame($old['dependencies'][0]['payload']['stable_key'], $new['dependencies'][0]['payload']['stable_key']);
        self::assertNotSame($old['dependencies'][1]['payload']['stable_key'], $new['dependencies'][1]['payload']['stable_key']);
        self::assertNotSame($old['evidence_idempotency_key'], $new['evidence_idempotency_key']);
        self::assertStringNotContainsString(self::VARIANT, $new['reuse_scope']);
    }

    public function test_same_external_source_reuses_dependency_identity_across_capture_retries(): void
    {
        $planner = new CaptureVideoProvenancePlanner();
        $subject = ['id' => self::VARIANT, 'type' => 'variant', 'name' => 'Variant A'];
        $first = $planner->plan('capture-one', $this->videoProposal('samevideo11'), $this->snapshot('samevideo11'), $subject);
        $retry = $planner->plan('capture-two', $this->videoProposal('samevideo11'), $this->snapshot('samevideo11'), $subject);

        self::assertSame($first['dependencies'][0]['payload']['stable_key'], $retry['dependencies'][0]['payload']['stable_key']);
        self::assertSame($first['dependencies'][0]['idempotency_key'], $retry['dependencies'][0]['idempotency_key']);
        self::assertSame($first['dependencies'][1]['payload']['stable_key'], $retry['dependencies'][1]['payload']['stable_key']);
        self::assertSame($first['evidence_idempotency_key'], $retry['evidence_idempotency_key']);
    }

    public function test_ambiguous_source_does_not_create_dependency_or_about_relation(): void
    {
        $planner = new CaptureVideoProvenancePlanner();

        $plan = $planner->plan(
            'capture-ambiguous',
            $this->videoProposal('ambiguous11'),
            $this->snapshot('ambiguous11', 'Một video đồng hồ cổ không rõ mẫu'),
            ['id' => self::VARIANT, 'type' => 'variant', 'name' => 'Variant A'],
        );

        self::assertSame('REVIEW_REQUIRED', $plan['status']);
        self::assertContains('SOURCE_SUBJECT_IDENTITY_UNCONFIRMED', $plan['blockers']);
        self::assertSame([], $plan['dependencies']);
        self::assertSame([], $plan['video_proposal']['payload']['metadata']['semantic_attachments']);
    }

    public function test_variant_reference_in_canonical_stable_key_can_confirm_source_identity(): void
    {
        $planner = new CaptureVideoProvenancePlanner();

        $plan = $planner->plan(
            'capture-reference',
            $this->videoProposal('reference11'),
            $this->snapshot('reference11', 'Video Ref 36/8 – source snapshot'),
            ['id' => self::VARIANT, 'type' => 'variant', 'stable_key' => 'nhk:variant:ref.36.8', 'name' => 'Đồng hồ Ref 36/8'],
        );

        self::assertSame('READY', $plan['status']);
    }

    public function test_exact_variant_reference_in_source_title_can_confirm_current_variant_without_brand_fallback(): void
    {
        $plan = (new CaptureVideoProvenancePlanner())->plan(
            'capture-odo-36-8',
            $this->videoProposal('odo36eight1'),
            $this->snapshot('odo36eight1', 'Số 67 – Ô-đô 36/8 Nguyên Bản – Đời Máy Ba Vách Bệt Đáng Sưu Tầm'),
            ['id' => '852da54d-457a-4397-a16d-52d9452ba766', 'type' => 'variant', 'name' => 'Odo 36/8'],
        );

        self::assertSame('READY', $plan['status']);
    }

    public function test_marketing_and_conflicting_classification_are_never_promoted(): void
    {
        $planner = new CaptureVideoProvenancePlanner();

        $plan = $planner->plan(
            'capture-conflict',
            $this->videoProposal('conflict1111'),
            $this->snapshot('conflict1111', 'Variant A – côn đồng – tốt nhất – zin tuyệt đối'),
            [
                'id' => self::VARIANT,
                'type' => 'variant',
                'name' => 'Variant A',
                'aliases' => ['A'],
            ],
            ['user_hint' => 'côn thép'],
        );

        self::assertSame('READY', $plan['status']);
        self::assertSame(['Côn đồng', 'Côn thép'], $plan['unsupported_classifications']);
        self::assertStringNotContainsString('côn đồng', strtolower($plan['dependencies'][1]['payload']['text']));
        self::assertStringNotContainsString('côn thép', strtolower($plan['dependencies'][1]['payload']['text']));
        self::assertStringNotContainsString('tốt nhất', strtolower($plan['dependencies'][1]['payload']['text']));
    }

    public function test_capture_applies_source_claim_evidence_then_video_with_exact_evidence_ref(): void
    {
        $ids = array_map(static fn (): string => UuidCodec::newV7(), range(1, 4));
        $created = [];
        $proposals = [];
        $reviewCounts = [];
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::exactly(4))->method('createFromArguments')->willReturnCallback(function (array $arguments) use (&$created, &$proposals, $ids): Proposal {
            $created[] = $arguments;
            $index = count($created) - 1;
            $proposal = new Proposal($ids[$index], (string) ($arguments['subject_id'] ?? 'subject'), 'ingest', (array) ($arguments['payload'] ?? []), 'content-' . $index, null, 'dependency-' . $index, ProposalState::DRAFT, idempotencyKey: (string) ($arguments['idempotency_key'] ?? ''), entityType: (string) ($arguments['entity_type'] ?? ''));
            $proposals[] = $proposal;
            return $proposal;
        });
        $governance->method('review')->willReturnCallback(function (string $id) use (&$reviewCounts, &$proposals): array {
            $reviewCounts[$id] = ($reviewCounts[$id] ?? 0) + 1;
            $proposal = array_values(array_filter($proposals, static fn (Proposal $item): bool => $item->id === $id))[0];
            return ['state' => $reviewCounts[$id] === 1 ? 'draft' : 'submitted', 'entity_type' => $proposal->entityType, 'operation' => $proposal->operation, 'subject_id' => $proposal->subjectId, 'payload' => $proposal->payload, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint];
        });
        $governance->method('submit')->willReturnCallback(static function (string $id) use (&$proposals): Proposal { return array_values(array_filter($proposals, static fn (Proposal $item): bool => $item->id === $id))[0]; });
        $governance->method('approve')->willReturnCallback(static function (string $id) use (&$proposals): Proposal { return array_values(array_filter($proposals, static fn (Proposal $item): bool => $item->id === $id))[0]->transition(ProposalState::APPROVED, 'test'); });
        $governance->method('eligibility')->willReturn(['ready' => true]);
        $service = new GovernedCaptureContinuationService(
            $governance,
            static function (string $id) use ($ids): array {
                $index = array_search($id, $ids, true);
                return ['canonical_id' => $ids[$index], 'canonical_readback' => ['canonical_id' => $ids[$index], 'active' => true, 'revision' => 1], 'idempotent' => false];
            },
            new GovernanceAutomationPolicyResolver(['source', 'knowledge', 'evidence', 'video'], new class implements AutomationPolicyStorage {
                public function read(): array { return ['source' => 'AUTO_PUBLISH', 'knowledge' => 'AUTO_PUBLISH', 'evidence' => 'AUTO_PUBLISH', 'video' => 'AUTO_PUBLISH']; }
                public function write(array $policies): void {}
            }),
            static fn (string $capability): bool => true,
            null,
            new CaptureVideoProvenancePlanner(),
        );

        $result = $service->execute('capture-a', 'capture-a:semantic', [
            'subject_resolution' => ['primary' => ['id' => self::VARIANT, 'type' => 'variant', 'name' => 'Variant A'], 'resolved' => [['id' => self::VARIANT, 'type' => 'variant', 'name' => 'Variant A']]],
            'interpretation' => [],
            'observations' => [],
            'assets' => [['kind' => 'video', 'video_proposal' => ['operation' => 'ingest', 'entity_type' => 'video', 'payload' => ['url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'abcdefghijk', 'canonical_source_url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'source_title' => 'Variant A – source snapshot']]]]]],
        ], ['approval_confirmed' => true]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame(['source', 'knowledge', 'evidence', 'video'], array_column($created, 'entity_type'));
        self::assertSame($ids[0], $created[2]['payload']['source_id']);
        self::assertSame($ids[1], $created[2]['payload']['claim_id']);
        self::assertSame([['evidence_id' => $ids[2]]], $created[3]['payload']['metadata']['semantic_attachments'][0]['evidence_refs']);
    }

    private function videoProposal(string $externalId): array
    {
        return ['operation' => 'ingest', 'entity_type' => 'video', 'payload' => ['url' => 'https://www.youtube.com/watch?v=' . $externalId, 'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => $externalId]]]];
    }

    private function snapshot(string $externalId, string $title = 'Variant A – source snapshot'): array
    {
        return ['platform' => 'youtube', 'external_video_id' => $externalId, 'canonical_source_url' => 'https://www.youtube.com/watch?v=' . $externalId, 'source_title' => $title];
    }
}
