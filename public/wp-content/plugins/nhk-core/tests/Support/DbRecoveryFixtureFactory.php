<?php
declare(strict_types=1);

namespace NHK\Tests\Support;

use NHK\Core\Application\Capture\{ContentPreparationOrchestrator, EditorialCaptureContinuationService, EditorialCaptureCoordinator};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Application\Mcp\{McpGovernanceHandler, McpReadHandler, McpTransport};
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\{Authority\AuthorityRepository, Knowledge\EvidenceRepository, Knowledge\KnowledgeRepository, Media\MediaAssetRepository, Media\MediaRepository, Media\MediaUsageRepository, Video\VideoRepository};
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, EntityTypeRegistry};
use NHK\Core\Domain\Capture\{CaptureRecord, CaptureStage};
use NHK\Core\Domain\Video\Video;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Capture\WpdbCaptureRepository;
use NHK\Core\Infrastructure\Migration\{AuthorityMigration002, EditorialCaptureMigration017, MediaMigration004};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Shared\Uuid\UuidCodec;

/** DB-backed recovery fixture for the public MCP Capture entry path. */
final class DbRecoveryFixtureFactory
{
    public string $captureId;
    public string $idempotencyKey;
    public string $subjectId;
    public string $videoId;
    public string $platform = 'youtube';
    public string $externalVideoId;
    public string $subjectStableKey;
    public string $subjectName;

    /** @var list<string> */
    private array $ownedCaptureIds = [];
    /** @var list<string> */
    private array $ownedVideoIds = [];
    /** @var list<string> */
    private array $ownedSubjectIds = [];

    /** @param array<string,mixed> $options */
    public function __construct(array $options = [])
    {
        $this->captureId = UuidCodec::newV7();
        $this->idempotencyKey = 'integration-recovery-' . bin2hex(random_bytes(8));
        $this->subjectId = UuidCodec::newV7();
        $this->videoId = UuidCodec::newV7();
        $this->externalVideoId = 'recovery-' . bin2hex(random_bytes(8));
        $this->subjectStableKey = 'nhk:test:model.' . bin2hex(random_bytes(6));
        $this->subjectName = 'Integration Model ' . bin2hex(random_bytes(4));
        $this->persistSubject();
        $this->persistLegacyCapture($options);
    }

    public function request(): array
    {
        $checkpoint = (new \NHK\Core\Application\Mcp\McpDocumentationRegistry())->bootstrap();
        return [
            'capture_id' => $this->captureId,
            'idempotency_key' => $this->idempotencyKey,
            'resume_mode' => 'RETRY',
            'resume_children' => ['video'],
            'documentation_checkpoint' => [
                'manifest_hash' => $checkpoint['manifest_hash'],
                'documentation_version' => $checkpoint['documentation_version'],
            ],
        ];
    }

    /** @param array<string,mixed> $faults */
    public function transport(array $faults = []): McpTransport
    {
        global $wpdb;
        $captures = new WpdbCaptureRepository($wpdb);
        $videos = new WpdbVideoRepository($wpdb);
        $attempts = [];

        $semanticWrite = function (array $context) use ($videos, $faults, &$attempts): array {
            $attempts['semantic'] = ($attempts['semantic'] ?? 0) + 1;
            $metadata = [
                'subject_resolution_packet' => $context['subject_resolution_packet'] ?? [],
                'integration_fixture' => true,
            ];
            $candidate = new Video(
                $this->videoId,
                $this->platform,
                $this->externalVideoId,
                'https://www.youtube.com/watch?v=' . $this->externalVideoId,
                'Integration recovery video',
                $metadata,
                null,
                true,
            );
            if (($faults['before_owner_commit'] ?? false) === true && ($attempts['semantic'] ?? 0) === 1) {
                throw new \RuntimeException('INJECTED_FAILURE_BEFORE_OWNER_COMMIT');
            }
            $owner = $videos->findByExternalReference($this->platform, $this->externalVideoId);
            if ($owner === null) $owner = $videos->create($candidate);
            if (($faults['owner_persisted_then_crash'] ?? false) === true && ($attempts['semantic'] ?? 0) === 1) {
                throw new \RuntimeException('INJECTED_FAILURE_AFTER_OWNER_PERSISTENCE');
            }
            if (($faults['partial_receipt_then_crash'] ?? false) === true && ($attempts['semantic'] ?? 0) === 1) {
                throw new \RuntimeException('INJECTED_FAILURE_AFTER_INTERMEDIATE_RECEIPT');
            }
            return [
                'status' => $owner->canonicalId === $this->videoId ? 'APPLIED' : 'REUSED',
                'writes' => [[
                    'entity_type' => 'video',
                    'canonical_id' => $owner->canonicalId,
                    'canonical_readback' => ['canonical_id' => $owner->canonicalId, 'revision' => $owner->revision],
                ]],
                'canonical_readback' => ['canonical_id' => $owner->canonicalId, 'revision' => $owner->revision],
            ];
        };

        $coordinator = new EditorialCaptureCoordinator(
            $captures,
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => [],
            new TextInputInterpreter(),
            new SubjectResolutionService(function (string $value): array {
                global $wpdb;
                $repository = new WpdbAuthorityRepository($wpdb);
                $entity = UuidCodec::isValid($value)
                    ? $repository->findByCanonicalId($value)
                    : $repository->findByStableKey('model', $value);
                return $entity instanceof AuthorityEntity ? [[
                    'id' => $entity->canonicalId,
                    'type' => $entity->entityType,
                    'stable_key' => $entity->stableKey,
                    'name' => $entity->canonicalName,
                    'revision' => $entity->revision,
                ]] : [];
            }),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            $semanticWrite,
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'not_requested', 'items' => []],
            static fn (array $context): array => ['eligible' => true, 'blockers' => []],
            static fn (array $context): array => ['status' => 'verified', 'article_owner' => 'NOT_REQUIRED'],
            null,
            null,
            null,
            new \NHK\Core\Application\Mcp\McpDocumentationRegistry(),
            function (array $context): array {
                $subject = is_array($context['subject_resolution'] ?? null) ? $context['subject_resolution'] : [];
                $packet = is_array($subject['primary'] ?? null) ? $subject['primary'] : [];
                return [
                    'status' => 'verified',
                    'items' => [[
                        'kind' => 'video',
                        'video_id' => $this->videoId,
                        'video_proposal' => ['payload' => ['canonical_id' => $this->videoId, 'metadata' => ['subject_resolution_packet' => $subject]]],
                    ]],
                ];
            },
            function (array $context): array {
                return [
                    'status' => 'verified',
                    'items' => [[
                        'video_id' => $this->videoId,
                        'completion' => [
                            'owner_type' => 'video',
                            'owner_id' => $this->videoId,
                            'status' => 'COMPLETE',
                            'complete' => true,
                            'canonical_readback' => ['canonical_id' => $this->videoId],
                            'content_state' => 'CONTENT_COMPLETE',
                            'dependency_state' => 'COMPLETE',
                            'relation_or_usage_state' => 'COMPLETE',
                            'public_state' => 'READY',
                            'frontend_state' => 'VERIFIED',
                            'blockers' => [],
                        ],
                    ]],
                    'blockers' => [],
                ];
            },
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new ContentPreparationOrchestrator(
                new SubjectResolutionService(static fn (string $value): array => []),
                static fn (array $context): array => ['status' => 'available', 'candidates' => [], 'total' => 0],
            ),
        );

        $read = new McpReadHandler(
            new WpdbAuthorityRepository($wpdb), new EntityTypeRegistry(),
            new WpdbMediaRepository($wpdb), new WpdbMediaAssetRepository($wpdb), new WpdbMediaUsageRepository($wpdb),
            $videos, new WpdbKnowledgeRepository($wpdb), new WpdbEvidenceRepository($wpdb), captures: $captures,
        );
        $continuation = new EditorialCaptureContinuationService($captures, new InMemoryCaptureAddendumRepository(), $coordinator);
        return new McpTransport($read, new McpGovernanceHandler(new GovernanceService(new InMemoryProposalRepository())), static fn (string $capability): bool => true, documentation: new \NHK\Core\Application\Mcp\McpDocumentationRegistry(), capture: $coordinator, captureContinuation: $continuation);
    }

    public function countVideos(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'nhk_videos WHERE platform=%s AND external_video_id=%s', $this->platform, $this->externalVideoId));
    }

    public function persistExistingVideo(): void
    {
        global $wpdb;
        $metadata = ['subject_resolution_packet' => $this->readCapture()->context['subject_resolution_packet'] ?? [], 'integration_fixture' => true];
        (new WpdbVideoRepository($wpdb))->create(new Video(
            $this->videoId,
            $this->platform,
            $this->externalVideoId,
            'https://www.youtube.com/watch?v=' . $this->externalVideoId,
            'Integration recovery video',
            $metadata,
            null,
            true,
        ));
    }

    public function readCapture(): CaptureRecord
    {
        global $wpdb;
        $capture = (new WpdbCaptureRepository($wpdb))->findById($this->captureId);
        if (!$capture instanceof CaptureRecord) throw new \RuntimeException('fixture_capture_missing');
        return $capture;
    }

    public function cleanup(): void
    {
        global $wpdb;
        TestDatabaseGuard::assertDestructiveAllowed((string) $wpdb->get_var('SELECT DATABASE()'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_editorial_captures WHERE capture_uuid=%s', UuidCodec::toBinary($this->captureId)));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_videos WHERE canonical_uuid=%s', UuidCodec::toBinary($this->videoId)));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'nhk_entities WHERE canonical_uuid=%s', UuidCodec::toBinary($this->subjectId)));
    }

    private function persistSubject(): void
    {
        global $wpdb;
        (new AuthorityMigration002())->up();
        $entity = new AuthorityEntity($this->subjectId, 'model', $this->subjectStableKey, $this->subjectName, 1, ['integration_fixture' => true], AuthorityState::ACTIVE, 1);
        (new WpdbAuthorityRepository($wpdb))->create($entity);
        $this->ownedSubjectIds[] = $this->subjectId;
    }

    /** @param array<string,mixed> $options */
    private function persistLegacyCapture(array $options): void
    {
        global $wpdb;
        (new EditorialCaptureMigration017())->up();
        (new MediaMigration004())->up();
        $packet = [
            'packet_version' => 1,
            'status' => 'resolved',
            'canonical_subject_id' => $this->subjectId,
            'entity_type' => 'model',
            'stable_key' => $this->subjectStableKey,
            'canonical_name' => $this->subjectName,
            'revision' => 1,
            'match_reason' => 'user_confirmed',
            'primary_source' => 'USER_CONFIRMED_SUBJECT_RECONCILIATION',
        ];
        $record = new CaptureRecord(
            $this->captureId,
            $this->idempotencyKey,
            hash('sha256', $this->idempotencyKey),
            CaptureStage::SEMANTICS_RECONCILED->value,
            'REVIEW_REQUIRED',
            null,
            null,
            [],
            [
                'purpose' => 'EDITORIAL',
                'raw_input' => 'Ambiguous integration hint.',
                'content_intent' => ['intent' => 'VIDEO', 'article_required' => false],
                'subject_resolution_packet' => $packet,
                'original_request' => ['intent' => 'VIDEO', 'video' => ['url' => 'https://www.youtube.com/watch?v=' . $this->externalVideoId]],
            ],
            [
                'subjects' => ['status' => 'resolved', 'primary' => ['id' => $this->subjectId, 'type' => 'model', 'revision' => 1]],
                'content_preparation' => ['status' => 'REVIEW_REQUIRED', 'preparation_fingerprint' => hash('sha256', 'legacy'), 'quality_decision' => 'READY', 'review_reasons' => ['PRIMARY_SUBJECT_AMBIGUOUS']],
                'completion' => ['status' => 'REVIEW_REQUIRED', 'blockers' => [], 'resume_hints' => ['resume_children' => ['video']]],
                'failure' => ['code' => 'VIDEO_EDITORIAL_QUALITY_BLOCKED', 'classification' => 'REVIEW_REQUIRED'],
            ],
            [
                'VIDEO_ENRICHED' => ['status' => 'FAILED', 'result' => 'FAILED_RETRYABLE', 'failure_code' => 'VIDEO_EDITORIAL_QUALITY_BLOCKED'],
                'CONTENT_PREPARATION' => ['status' => 'REVIEW_REQUIRED', 'result' => 'REVIEW_REQUIRED', 'failure_code' => 'VIDEO_EDITORIAL_QUALITY_BLOCKED'],
            ],
            revision: 18,
        );
        (new WpdbCaptureRepository($wpdb))->create($record);
        $this->ownedCaptureIds[] = $this->captureId;
    }

}

final class InMemoryCaptureAddendumRepository implements \NHK\Core\Contracts\Capture\CaptureAddendumRepository
{
    /** @var array<string,\NHK\Core\Domain\Capture\CaptureAddendumRecord> */
    private array $records = [];
    public function findByIdempotencyKey(string $key): ?\NHK\Core\Domain\Capture\CaptureAddendumRecord { return $this->records[$key] ?? null; }
    public function create(\NHK\Core\Domain\Capture\CaptureAddendumRecord $record): \NHK\Core\Domain\Capture\CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
    public function save(\NHK\Core\Domain\Capture\CaptureAddendumRecord $record): \NHK\Core\Domain\Capture\CaptureAddendumRecord { return $this->records[$record->idempotencyKey] = $record; }
}
