<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\CaptureVideoProvenancePlanner;
use NHK\Core\Application\Capture\GovernedCaptureContinuationService;
use NHK\Core\Application\Governance\GovernanceAutomationPolicyResolver;
use NHK\Core\Application\Video\{VideoRelationCandidatePlanner, YouTubeSourceAdapter};
use NHK\Core\Contracts\Governance\{AutomationPolicyStorage, GovernedLifecycle};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Domain\Governance\{Proposal, ProposalState};
use NHK\Core\Domain\Graph\PredicateRegistry;
use NHK\Core\Domain\Knowledge\{Evidence, KnowledgeClaim, Source};
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
            [['target_type' => 'variant', 'target_uuid' => self::VARIANT, 'predicate' => 'about', 'origin' => 'EXPLICIT_USER_RELATION', 'reason' => 'Source-specific provenance handoff.', 'confidence' => 1.0, 'evidence_refs' => [['evidence_id' => 'evidence-uuid']]]],
            $completed['video_proposal']['payload']['metadata']['semantic_attachments'],
        );

        $prepared = $planner->attachEvidenceDependency($plan, 'source-uuid', 'claim-uuid');
        self::assertSame([], $prepared['video_proposal']['payload']['metadata']['semantic_attachments']);
        self::assertSame('source-uuid', $prepared['dependencies'][2]['payload']['source_id']);
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

    public function test_official_source_description_can_confirm_locked_subject_without_becoming_evidence(): void
    {
        $plan = (new CaptureVideoProvenancePlanner())->plan(
            'capture-source-description',
            $this->videoProposal('gsjfYNH2r6M'),
            $this->snapshot('gsjfYNH2r6M', 'Collection video – archival record') + [
                'source_description' => 'Official description identifies Odo Jacquemart, model record.',
                'channel_title' => 'NHK official archive',
            ],
            ['id' => '30515de5-efe5-48e1-aec5-34130509a4dc', 'type' => 'model', 'name' => 'Odo Jacquemart'],
        );

        self::assertSame('READY', $plan['status']);
        self::assertSame(['source_description'], $plan['diagnostics']['identity_matches']);
        self::assertSame('Collection video – archival record', $plan['evidence']['excerpt']);
        self::assertStringNotContainsString('Official description identifies', $plan['evidence']['excerpt']);
    }

    public function test_locked_model_identity_can_be_confirmed_across_trusted_youtube_fields(): void
    {
        $plan = (new CaptureVideoProvenancePlanner())->plan(
            'capture-odo-jacquemart-split',
            $this->videoProposal('gsjfYNH2r6M'),
            [
                ...$this->snapshot('gsjfYNH2r6M', 'Ô Đô “ông Tây đánh chuông” – một thiết kế rất thú vị của đồng hồ Pháp.'),
                'source_description' => 'Điểm đặc biệt là hình người Jacquemart cầm búa chuyển động để gõ trực tiếp vào chiếc chuông phía trên mặt số mỗi khi đồng hồ điểm.',
                'channel_title' => 'NHK official archive',
            ],
            $this->odoJacquemartSubject(),
        );

        self::assertSame('READY', $plan['status']);
        self::assertSame(['source_title', 'source_description'], $plan['diagnostics']['identity_matches']);
    }

    public function test_locked_model_identity_can_be_confirmed_when_components_share_one_trusted_field(): void
    {
        $plan = (new CaptureVideoProvenancePlanner())->plan(
            'capture-odo-jacquemart-one-field',
            $this->videoProposal('gsjfYNH2r6M'),
            [
                ...$this->snapshot('gsjfYNH2r6M', 'Official Odo Jacquemart archival record'),
                'channel_title' => 'NHK official archive',
            ],
            $this->odoJacquemartSubject(),
        );

        self::assertSame('READY', $plan['status']);
        self::assertSame(['source_title'], $plan['diagnostics']['identity_matches']);
    }

    /** @dataProvider unconfirmedOdoIdentityCases */
    public function test_locked_model_identity_fails_closed_without_all_canonical_components(array $source, array $context = []): void
    {
        $plan = (new CaptureVideoProvenancePlanner())->plan(
            'capture-odo-jacquemart-negative',
            $this->videoProposal('gsjfYNH2r6M'),
            [...$this->snapshot('gsjfYNH2r6M', (string) ($source['source_title'] ?? '')), ...$source],
            $this->odoJacquemartSubject(),
            $context,
        );

        self::assertSame('REVIEW_REQUIRED', $plan['status']);
        self::assertContains('SOURCE_SUBJECT_IDENTITY_UNCONFIRMED', $plan['blockers']);
        self::assertSame([], $plan['dependencies']);
        self::assertSame([], $plan['video_proposal']['payload']['metadata']['semantic_attachments']);
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:array<string,string>}> */
    public static function unconfirmedOdoIdentityCases(): iterable
    {
        yield 'Odo without Jacquemart' => [[
            'source_title' => 'Ô Đô “ông Tây đánh chuông” – một thiết kế rất thú vị của đồng hồ Pháp.',
            'source_description' => 'Generic official description of a mechanical French clock.',
            'channel_title' => 'NHK official archive',
        ], []];
        yield 'Jacquemart without Odo' => [[
            'source_title' => 'A mechanical clock archival record',
            'source_description' => 'Jacquemart is the animated bell striker in this clock.',
            'channel_title' => 'NHK official archive',
        ], []];
        yield 'user hint is not source identity' => [[
            'source_title' => 'Official archive record',
            'source_description' => 'Generic official description with no locked subject identity.',
            'channel_title' => 'NHK official archive',
        ], ['user_hint' => 'Odo Jacquemart']];
        yield 'Odo with a different model' => [[
            'source_title' => 'Ô Đô 36 – một mẫu đồng hồ khác',
            'source_description' => 'Official description of another model.',
            'channel_title' => 'NHK official archive',
        ], []];
        yield 'generic French clock wording' => [[
            'source_title' => 'Ông Tây đánh chuông – đồng hồ Pháp cổ',
            'source_description' => 'Đồng hồ Pháp với cơ cấu chuông chuyển động.',
            'channel_title' => 'NHK official archive',
            'tags' => ['Odo Jacquemart'],
            'title' => 'Odo Jacquemart',
            'description' => 'Odo Jacquemart',
        ], []];
    }

    public function test_source_metadata_not_identifying_locked_subject_fails_closed_even_with_user_hint(): void
    {
        $plan = (new CaptureVideoProvenancePlanner())->plan(
            'capture-source-negative',
            $this->videoProposal('gsjfYNH2r6M'),
            $this->snapshot('gsjfYNH2r6M', 'Collection video – archival record') + [
                'source_description' => 'Official description contains no model identity.',
                'channel_title' => 'NHK official archive',
                'tags' => ['archive', 'horology'],
            ],
            ['id' => '30515de5-efe5-48e1-aec5-34130509a4dc', 'type' => 'model', 'name' => 'Odo Jacquemart'],
            ['user_hint' => 'Odo Jacquemart'],
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

    public function test_video_only_resume_rehydrates_locked_subject_from_original_video_packet(): void
    {
        $plan = (new CaptureVideoProvenancePlanner())->plan(
            'capture-resume',
            [
                'operation' => 'ingest',
                'entity_type' => 'video',
                'payload' => [
                    'canonical_id' => UuidCodec::newV7(),
                    'metadata' => [
                        'source' => [
                            'platform' => 'youtube',
                            'external_video_id' => '4NmkQFrNeWQ',
                            'canonical_source_url' => 'https://www.youtube.com/watch?v=4NmkQFrNeWQ',
                            'source_title' => 'Odo 36/8 source snapshot',
                        ],
                        'subject_resolution_packet' => [
                            'id' => '852da54d-457a-4397-a16d-52d9452ba766',
                            'type' => 'variant',
                            'name' => 'Odo 36/8',
                        ],
                    ],
                ],
            ],
            [],
            ['id' => UuidCodec::newV7(), 'type' => 'variant', 'name' => 'Wrong reparse'],
            ['preserve_original_subject' => true],
        );

        self::assertSame('READY', $plan['status']);
        self::assertSame('852da54d-457a-4397-a16d-52d9452ba766', $plan['relation']['target_uuid']);
        self::assertSame('4NmkQFrNeWQ', $plan['dependencies'][0]['payload']['metadata']['external_video_id']);
        self::assertSame('4NmkQFrNeWQ', $plan['dependencies'][1]['payload']['provenance']['metadata']['external_video_id']);
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
            'assets' => [['kind' => 'video', 'video_proposal' => ['operation' => 'ingest', 'entity_type' => 'video', 'subject_id' => $ids[3], 'payload' => ['canonical_id' => $ids[3], 'url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => 'abcdefghijk', 'canonical_source_url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'source_title' => 'Variant A – source snapshot']]]]]],
        ], ['approval_confirmed' => true]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame(['source', 'knowledge', 'evidence', 'video'], array_column($created, 'entity_type'));
        self::assertSame($ids[0], $created[2]['payload']['source_id']);
        self::assertSame($ids[1], $created[2]['payload']['claim_id']);
        self::assertSame([['evidence_id' => $ids[2]]], $created[3]['payload']['metadata']['semantic_attachments'][0]['evidence_refs']);
    }

    public function test_capture_video_governed_dependency_e2e_reuses_source_and_reads_back_one_canonical_chain(): void
    {
        $subjectId = '30515de5-efe5-48e1-aec5-34130509a4dc';
        $videoId = '40515de5-efe5-48e1-aec5-34130509a4dc';
        $canonicalIds = [
            '50515de5-efe5-48e1-aec5-34130509a4dc',
            '60515de5-efe5-48e1-aec5-34130509a4dc',
            '70515de5-efe5-48e1-aec5-34130509a4dc',
        ];
        $governance = new class implements GovernedLifecycle {
            /** @var array<string,Proposal> */
            public array $proposals = [];
            public int $created = 0;

            public function createFromArguments(array $arguments): Proposal
            {
                ++$this->created;
                $proposal = new Proposal(
                    UuidCodec::newV7(),
                    (string) ($arguments['subject_id'] ?? 'subject'),
                    'ingest',
                    (array) ($arguments['payload'] ?? []),
                    hash('sha256', json_encode($arguments['payload'] ?? [], JSON_THROW_ON_ERROR)),
                    null,
                    hash('sha256', (string) ($arguments['idempotency_key'] ?? '')),
                    ProposalState::DRAFT,
                    idempotencyKey: (string) ($arguments['idempotency_key'] ?? ''),
                    entityType: (string) ($arguments['entity_type'] ?? ''),
                );
                return $this->proposals[$proposal->id] = $proposal;
            }

            public function findByIdempotencyKey(string $key): ?Proposal
            {
                foreach ($this->proposals as $proposal) if ($proposal->idempotencyKey === $key) return $proposal;
                return null;
            }

            public function submit(string $id): Proposal { return $this->proposals[$id] = $this->proposals[$id]->transition(ProposalState::SUBMITTED, 'test'); }

            public function review(string $id): array
            {
                $proposal = $this->proposals[$id];
                return ['state' => $proposal->state->value, 'entity_type' => $proposal->entityType, 'operation' => $proposal->operation, 'subject_id' => $proposal->subjectId, 'payload' => $proposal->payload, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint];
            }

            public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { return $this->proposals[$id] = $this->proposals[$id]->transition(ProposalState::APPROVED, $actor); }

            public function eligibility(string $id): array { return ['ready' => true]; }
        };
        $state = ['source' => null, 'claim' => null, 'evidence' => []];
        $createdCanonical = ['source' => 0, 'claim' => 0, 'evidence' => 0, 'video' => 0];
        $officialSnapshot = (new YouTubeSourceAdapter(static fn (object $identity): array => [
            'source_title' => 'Ô Đô “ông Tây đánh chuông” – một thiết kế rất thú vị của đồng hồ Pháp.',
            'source_description' => 'Điểm đặc biệt là hình người Jacquemart cầm búa chuyển động để gõ trực tiếp vào chiếc chuông phía trên mặt số mỗi khi đồng hồ điểm.',
            'channel_title' => 'NHK official archive',
        ]))->resolve('https://www.youtube.com/watch?v=gsjfYNH2r6M')->snapshot->toArray();
        $apply = function (string $proposalId) use (&$state, &$createdCanonical, $governance, $canonicalIds, $videoId): array {
            $proposal = $governance->proposals[$proposalId];
            $kind = $proposal->entityType === 'knowledge' ? 'claim' : $proposal->entityType;
            $map = ['source' => $canonicalIds[0], 'claim' => $canonicalIds[1], 'evidence' => $canonicalIds[2], 'video' => $videoId];
            $canonicalId = $map[$kind];
            if ($kind === 'source') $state['source'] = ['canonical_id' => $canonicalId, 'revision' => 1, 'active' => true];
            elseif ($kind === 'claim') $state['claim'] = ['canonical_id' => $canonicalId, 'revision' => 1, 'active' => true];
            elseif ($kind === 'evidence') $state['evidence'] = [['canonical_id' => $canonicalId, 'revision' => 1, 'claim_id' => $canonicalIds[1], 'source_id' => $canonicalIds[0], 'active' => true]];
            if (!isset($createdCanonical[$kind]) || $createdCanonical[$kind] === 0) ++$createdCanonical[$kind];
            if ($proposal->state === ProposalState::APPROVED) $governance->proposals[$proposalId] = $proposal->transition(ProposalState::APPLIED, 'test');
            return ['canonical_id' => $canonicalId, 'canonical_readback' => ['canonical_id' => $canonicalId, 'entity_type' => $kind === 'claim' ? 'knowledge' : $kind, 'active' => true, 'revision' => 1], 'idempotent' => false];
        };
        $stateReader = static function (array $plan) use (&$state): array {
            return $state + ['subject' => ['id' => '30515de5-efe5-48e1-aec5-34130509a4dc', 'type' => 'model']];
        };
        $policies = new GovernanceAutomationPolicyResolver(['source', 'knowledge', 'evidence', 'video'], new class implements AutomationPolicyStorage {
            public function read(): array { return ['source' => 'AUTO_PUBLISH', 'knowledge' => 'AUTO_PUBLISH', 'evidence' => 'AUTO_PUBLISH', 'video' => 'AUTO_PUBLISH']; }
            public function write(array $policies): void {}
        });
        $service = new GovernedCaptureContinuationService($governance, $apply, $policies, static fn (string $capability): bool => true, null, new CaptureVideoProvenancePlanner(), null, $stateReader);
        $context = [
            'subject_resolution' => ['primary' => ['id' => $subjectId, 'type' => 'model', 'name' => 'Odo Jacquemart'], 'resolved' => [['id' => $subjectId, 'type' => 'model', 'name' => 'Odo Jacquemart']]],
            'interpretation' => [],
            'observations' => [],
            'assets' => [[
                'kind' => 'video',
                'video_proposal' => [
                    'operation' => 'ingest', 'entity_type' => 'video', 'subject_id' => $videoId, 'idempotency_key' => 'capture:video:gsjfYNH2r6M',
                    'payload' => ['canonical_id' => $videoId, 'url' => 'https://www.youtube.com/watch?v=gsjfYNH2r6M', 'metadata' => [
                        'source' => $officialSnapshot,
                        'subject_resolution_packet' => ['id' => $subjectId, 'type' => 'model', 'name' => 'Odo Jacquemart'],
                    ]],
                ],
            ]],
        ];

        $first = $service->execute('capture-gsjfYNH2r6M', 'capture-gsjfYNH2r6M:semantic', $context, ['approval_confirmed' => true]);
        $replay = $service->execute('capture-gsjfYNH2r6M', 'capture-gsjfYNH2r6M:semantic', $context, ['approval_confirmed' => true]);

        self::assertSame('APPLIED', $first['status']);
        self::assertSame('APPLIED', $replay['status']);
        self::assertSame(4, $governance->created, 'Replay must reuse the same governed dependency identities.');
        self::assertSame(['source' => 1, 'claim' => 1, 'evidence' => 1, 'video' => 1], $createdCanonical);
        self::assertSame($canonicalIds[0], $state['source']['canonical_id']);
        self::assertSame($canonicalIds[1], $state['claim']['canonical_id']);
        self::assertSame($canonicalIds[2], $state['evidence'][0]['canonical_id']);
        self::assertSame([['evidence_id' => $canonicalIds[2]]], $first['writes'][3]['evidence_handoff']['relation_evidence_refs']);
        self::assertSame([['evidence_id' => $canonicalIds[2]]], $replay['writes'][3]['evidence_handoff']['relation_evidence_refs']);
        self::assertSame('REUSED_VERIFIED', $replay['writes'][0]['status']);
        self::assertSame('REUSED_VERIFIED', $replay['writes'][1]['status']);
        self::assertSame('REUSED_VERIFIED', $replay['writes'][2]['status']);
    }

    public function test_explicit_video_resume_hands_canonical_evidence_to_relation_candidate_without_reapplying_knowledge(): void
    {
        $ids = array_map(static fn (): string => UuidCodec::newV7(), range(1, 4));
        $created = [];
        $proposals = [];
        $governance = $this->createMock(GovernedLifecycle::class);
        $governance->expects(self::exactly(4))->method('createFromArguments')->willReturnCallback(function (array $arguments) use (&$created, &$proposals, $ids): Proposal {
            $created[] = $arguments;
            $index = count($created) - 1;
            $proposal = new Proposal($ids[$index], (string) ($arguments['subject_id'] ?? 'subject'), 'ingest', (array) ($arguments['payload'] ?? []), 'content-' . $index, null, 'dependency-' . $index, ProposalState::DRAFT, idempotencyKey: (string) ($arguments['idempotency_key'] ?? ''), entityType: (string) ($arguments['entity_type'] ?? ''));
            $proposals[] = $proposal;
            return $proposal;
        });
        $governance->method('review')->willReturnCallback(function (string $id) use (&$proposals): array {
            $proposal = array_values(array_filter($proposals, static fn (Proposal $item): bool => $item->id === $id))[0];
            return ['state' => 'draft', 'entity_type' => $proposal->entityType, 'operation' => $proposal->operation, 'subject_id' => $proposal->subjectId, 'payload' => $proposal->payload, 'content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint];
        });
        $governance->method('submit')->willReturnCallback(static function (string $id) use (&$proposals): Proposal { return array_values(array_filter($proposals, static fn (Proposal $item): bool => $item->id === $id))[0]; });
        $governance->method('approve')->willReturnCallback(static function (string $id) use (&$proposals): Proposal { return array_values(array_filter($proposals, static fn (Proposal $item): bool => $item->id === $id))[0]->transition(ProposalState::APPROVED, 'test'); });
        $governance->method('eligibility')->willReturn(['ready' => true]);

        $claims = new class($ids[1]) implements KnowledgeRepository {
            public function __construct(private string $id) {}
            public function findByCanonicalId(string $id): ?KnowledgeClaim { return $id === $this->id ? new KnowledgeClaim($id, 'test:provenance', 'Source identifies the Video.') : null; }
            public function findByStableKey(string $stableKey): ?KnowledgeClaim { return null; }
            public function create(KnowledgeClaim $claim): KnowledgeClaim { return $claim; }
            public function update(KnowledgeClaim $claim, int $expectedRevision): KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $sources = new class($ids[0]) implements SourceRepository {
            public function __construct(private string $id) {}
            public function findByCanonicalId(string $id): ?Source { return $id === $this->id ? new Source($id, 'test:video-source', 'Variant A source', 'website', 'https://youtube.test/abcdefghijk') : null; }
            public function findByStableKey(string $stableKey): ?Source { return null; }
            public function create(Source $source): Source { return $source; }
            public function update(Source $source, int $expectedRevision): Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class($ids[2], $ids[1], $ids[0]) implements EvidenceRepository {
            public function __construct(private string $id, private string $claimId, private string $sourceId) {}
            public function findByCanonicalId(string $id): ?Evidence { return $id === $this->id ? new Evidence($id, $this->claimId, $this->sourceId, 'supports', 'Variant A source', 'https://youtube.test/abcdefghijk') : null; }
            public function create(Evidence $evidence): Evidence { return $evidence; }
            public function update(Evidence $evidence, int $expectedRevision): Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $relationPlanner = new VideoRelationCandidatePlanner(new PredicateRegistry(), $evidence, $claims, $sources);
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
            null,
            null,
            null,
            null,
            null,
            videoRelations: $relationPlanner,
        );

        $videoId = $ids[3];
        $result = $service->execute('01a09370-effb-7ebc-bf11-5f9fbd62c529', 'capture:450:resume-video', [
            'existing_capture_continuation' => true,
            'continuation_delta_text' => '',
            // The resumed Capture must not depend on reparsing the empty
            // addendum. The original immutable Video packet carries the
            // exact subject handoff instead.
            'subject_resolution' => ['primary' => null, 'resolved' => []],
            'interpretation' => ['user_claim_candidates' => array_map(static fn (int $index): array => ['text' => 'Completed claim ' . $index, 'provenance' => 'EXPLICIT_USER_KNOWLEDGE'], range(1, 7))],
            'observations' => [],
            'assets' => [[
                'kind' => 'video',
                'video_proposal' => [
                    'operation' => 'ingest',
                    'entity_type' => 'video',
                    'subject_id' => $videoId,
                    'payload' => [
                        'canonical_id' => $videoId,
                        'url' => 'https://www.youtube.com/watch?v=abcdefghijk',
                        'metadata' => [
                            'source' => ['platform' => 'youtube', 'external_video_id' => 'abcdefghijk', 'canonical_source_url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'source_title' => 'Variant A – source snapshot'],
                            'subject_resolution_packet' => ['id' => self::VARIANT, 'type' => 'variant', 'name' => 'Variant A'],
                            'semantic_attachments' => [['target_type' => 'variant', 'target_uuid' => self::VARIANT, 'predicate' => 'about', 'evidence_refs' => [['kind' => 'USER_HINT', 'value' => 'legacy']]]],
                        ],
                    ],
                ],
            ]],
        ], ['approval_confirmed' => true, 'resume_children' => ['video']]);

        self::assertSame('APPLIED', $result['status']);
        self::assertSame(['source', 'knowledge', 'evidence', 'video'], array_column($created, 'entity_type'));
        self::assertSame($videoId, $created[3]['payload']['canonical_id']);
        self::assertSame([['evidence_id' => $ids[2]]], $created[3]['payload']['metadata']['semantic_attachments'][0]['evidence_refs']);
        self::assertSame('variant', $created[3]['payload']['metadata']['semantic_attachments'][0]['target_type']);
        self::assertSame(self::VARIANT, $created[3]['payload']['metadata']['semantic_attachments'][0]['target_uuid']);
        self::assertSame($ids[0], $result['writes'][3]['evidence_handoff']['source_id']);
        self::assertSame($ids[1], $result['writes'][3]['evidence_handoff']['claim_id']);
        self::assertSame($ids[2], $result['writes'][3]['evidence_handoff']['evidence_id']);
        self::assertSame([['evidence_id' => $ids[2]]], $result['writes'][3]['evidence_handoff']['relation_evidence_refs']);
        self::assertNotContains('SEMANTIC_SUBJECT_OR_DELTA_REQUIRED', $result['blockers']);
    }

    private function videoProposal(string $externalId): array
    {
        return ['operation' => 'ingest', 'entity_type' => 'video', 'payload' => ['url' => 'https://www.youtube.com/watch?v=' . $externalId, 'metadata' => ['source' => ['platform' => 'youtube', 'external_video_id' => $externalId]]]];
    }

    private function snapshot(string $externalId, string $title = 'Variant A – source snapshot'): array
    {
        return ['platform' => 'youtube', 'external_video_id' => $externalId, 'canonical_source_url' => 'https://www.youtube.com/watch?v=' . $externalId, 'source_title' => $title];
    }

    /** @return array{id:string,type:string,stable_key:string,name:string} */
    private function odoJacquemartSubject(): array
    {
        return ['id' => '30515de5-efe5-48e1-aec5-34130509a4dc', 'type' => 'model', 'stable_key' => 'nhk:model:odo.jacquemar', 'name' => 'Odo Jacquemart'];
    }
}
