<?php
declare(strict_types=1);

namespace NHKTests\Unit;

use NHK\Core\Application\Governance\{ProposalEligibilityService, VideoProposalEligibilityEvaluator};
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Semantic\SubjectResolutionService;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\{DependencyRepository, EligibilityReader, ProposalRepository};
use NHK\Core\Contracts\Knowledge\{EvidenceRepository, KnowledgeRepository, SourceRepository};
use NHK\Core\Contracts\Media\MediaUsageRepository;
use NHK\Core\Domain\Media\MediaUsage;
use NHK\Core\Contracts\Video\VideoRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Governance\{DependencyGraph, Proposal, ProposalState};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\AuthorityEndpointResolver;
use PHPUnit\Framework\TestCase;

final class ProposalEligibilityServiceTest extends TestCase
{
    private const ID = '018f2f1e-7b2c-7abc-8def-0123456789ab';
    private const SUBJECT = '852da54d-457a-4397-a16d-52d9452ba766';

    public function test_video_ingest_without_semantic_attachment_can_reach_owner_apply(): void
    {
        $proposal = $this->proposal([
            'subject_resolution_packet' => ['id' => self::SUBJECT, 'type' => 'variant'],
            'source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'Video', 'summary' => 'Tóm tắt', 'body' => 'Nội dung'],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'semantic_attachments' => [],
        ]);
        $service = $this->service($proposal);

        self::assertTrue($service->check($proposal->id)->ready);
        self::assertSame([], $service->check($proposal->id)->reasons);
    }

    public function test_legacy_user_hint_evidence_is_not_eligible(): void
    {
        $proposal = $this->proposal([
            'subject_resolution_packet' => ['id' => self::SUBJECT, 'type' => 'variant', 'name' => 'Odo 36/8'],
            'source' => ['platform' => 'youtube', 'external_video_id' => 'dQw4w9WgXcQ', 'identity_valid' => true, 'availability' => 'available', 'embeddable' => true],
            'source_rights' => 'PUBLIC_EXTERNAL_REFERENCE',
            'editorial' => ['title' => 'Video', 'summary' => 'Tóm tắt', 'body' => 'Nội dung'],
            'embed_url' => 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            'semantic_attachments' => [[
                'target_type' => 'variant',
                'target_uuid' => self::SUBJECT,
                'predicate' => 'about',
                'evidence_refs' => [['kind' => 'USER_HINT', 'value' => 'Số 67']],
            ]],
        ]);
        $service = $this->service($proposal);

        self::assertFalse($service->check($proposal->id)->ready);
        self::assertSame(['CANONICAL_EVIDENCE_REQUIRED'], $service->check($proposal->id)->reasons);
    }

    public function test_video_scope_verifier_mismatch_is_not_collapsed_into_not_approved(): void
    {
        $proposal = new Proposal(self::ID, self::SUBJECT, 'ingest', [
            'canonical_id' => self::SUBJECT,
            'capture_id' => '01a0b2e0-1888-7038-9811-2dd7e7073a27',
            'staging_acceptance' => ['approved' => true],
            'metadata' => [],
        ], 'video-content', null, 'video-dependency', ProposalState::APPROVED, idempotencyKey: 'video-scope-diagnostic', entityType: 'video');
        $service = $this->service($proposal);
        $service->setStagingScopeVerifier(static fn (Proposal $checked): string => 'STAGING_VIDEO_PAYLOAD_MISMATCH');
        $service->setStagingScopeDiagnosticProvider(static fn (array $scope, Proposal $checked): array => [
            'SIGNED_NORMALIZED_DESCRIPTOR' => ['expected_revision' => 0],
            'VERIFIED_NORMALIZED_DESCRIPTOR' => ['expected_revision' => 0],
            'DESCRIPTOR_DIFF' => [['path' => 'payload_fingerprint', 'signed_type' => 'string', 'verified_type' => 'string', 'signed_hash' => str_repeat('a', 64), 'verified_hash' => str_repeat('b', 64)]],
        ]);

        $result = $service->check($proposal->id);
        self::assertContains('STAGING_VIDEO_PAYLOAD_MISMATCH', $result->reasons);
        self::assertSame(0, $result->diagnostics['SIGNED_NORMALIZED_DESCRIPTOR']['expected_revision']);
        self::assertArrayHasKey('DESCRIPTOR_DIFF', $result->diagnostics);
    }

    public function test_authority_apply_scope_is_checked_by_eligibility_with_the_same_semantics_as_apply(): void
    {
        $proposal = new Proposal(self::ID, self::SUBJECT, 'rename', [
            'capture_id' => '01a0b2e0-1888-7038-9811-2dd7e7073a27',
            'candidate_id' => 'candidate-authority-rename',
            'project_build_audit' => [
                'capture_id' => '01a0b2e0-1888-7038-9811-2dd7e7073a27',
                'plan_fingerprint' => str_repeat('a', 64),
            ],
        ], 'authority-content', 1, 'authority-dependency', ProposalState::APPROVED, idempotencyKey: 'authority-scope', targetUuid: self::SUBJECT, entityType: 'classification');
        $service = $this->service($proposal);
        $service->setStagingScopeVerifier(static fn (Proposal $checked): string => 'STAGING_SCOPE_REQUIRED');
        $service->setStagingScopeResolver(static fn (Proposal $checked): ?array => null);

        $result = $service->check($proposal->id);

        self::assertFalse($result->ready);
        self::assertContains('STAGING_SCOPE_REQUIRED', $result->reasons);
        self::assertSame('PERSISTED_CAPTURE_AUTHORITY_CONTEXT', $result->diagnostics['staging_scope']['resolution']);
    }

    public function test_authority_scope_resolver_can_make_eligibility_match_apply(): void
    {
        $proposal = new Proposal(self::ID, self::SUBJECT, 'rename', [
            'capture_id' => '01a0b2e0-1888-7038-9811-2dd7e7073a27',
            'candidate_id' => 'candidate-authority-rename',
            'project_build_audit' => [
                'capture_id' => '01a0b2e0-1888-7038-9811-2dd7e7073a27',
                'plan_fingerprint' => str_repeat('a', 64),
            ],
        ], 'authority-content', 1, 'authority-dependency', ProposalState::APPROVED, idempotencyKey: 'authority-scope-resolved', targetUuid: self::SUBJECT, entityType: 'classification');
        $service = $this->service($proposal);
        $service->setStagingScopeResolver(static fn (Proposal $checked): array => ['approved' => true]);
        $service->setStagingScopeVerifier(static fn (Proposal $checked): bool => true);

        self::assertTrue($service->check($proposal->id)->ready);
    }

    public function test_explicit_video_subject_packet_is_authoritative_over_unresolved_title_hint(): void
    {
        $proposal = $this->proposal([
            'source' => [
                'platform' => 'youtube',
                'external_video_id' => 'dQw4w9WgXcQ',
                'source_title' => 'title without a canonical match',
            ],
            'subject_resolution_packet' => ['id' => self::SUBJECT, 'type' => 'variant', 'name' => 'Odo 36/8'],
            'semantic_attachments' => [[
                'target_type' => 'variant',
                'target_uuid' => self::SUBJECT,
                'predicate' => 'about',
                'evidence_refs' => [['kind' => 'USER_HINT', 'value' => 'legacy']],
            ]],
        ]);
        $resolver = new SubjectResolutionService(static fn (string $hint): array => []);
        $reasons = $this->service($proposal, $resolver)->check($proposal->id)->reasons;

        self::assertNotContains('SUBJECT_UNRESOLVED', $reasons);
    }

    public function test_relation_create_skips_uuid_target_check_for_typed_wordpress_source(): void
    {
        $relation = new Proposal(self::ID, '1:487', 'relation_create', [
            'source_type' => 'wp_post', 'source_uuid' => '1:487', 'target_type' => 'classification',
            'target_uuid' => self::SUBJECT, 'source_revision' => 1789459547, 'target_revision' => 1,
        ], 'relation-content', null, 'relation-dependency', ProposalState::APPROVED, idempotencyKey: 'wp-post-relation', entityType: 'relation');
        $service = $this->service($relation, relationReader: new class implements EligibilityReader {
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return $targetUuid === '1:487' ? 1789459547 : 1; }
            public function targetExists(string $targetUuid): bool { throw new \LogicException('typed relation must not use generic targetExists'); }
        });

        self::assertTrue($service->check($relation->id)->ready);
    }

    public function test_media_usage_revision_is_read_from_usage_not_media_or_post_revision(): void
    {
        $usage = new MediaUsage('01a0b452-153e-79a7-81fd-cc7c2bfda2ef', self::SUBJECT, 'wp_post', '1:596', 'featured_primary', revision: 4, placementKey: 'featured_primary');
        $proposal = new Proposal(self::ID, self::SUBJECT, 'replace', [
            'media' => ['id' => self::SUBJECT],
            'target' => ['type' => 'wp_post', 'blog_id' => 1, 'post_id' => 596],
            'usage_id' => $usage->usageId,
            'expected_usage_revision' => 4,
        ], 'media-usage-content', 1, 'media-usage-dependency', ProposalState::APPROVED, targetUuid: null, entityType: 'media');
        $reader = new class implements EligibilityReader {
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return $targetUuid === '1:596' ? 999 : 9; }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        $usages = new class($usage) implements MediaUsageRepository {
            public function __construct(private MediaUsage $usage) {}
            public function create(MediaUsage $usage): MediaUsage { return $usage; }
            public function listByMediaId(string $mediaId, ?string $role = null): array { return []; }
            public function listByEndpoint(string $endpointType, string $endpointKey, ?string $role = null): array { return $endpointType === 'wp_post' && $endpointKey === '1:596' ? [$this->usage] : []; }
        };
        $service = $this->service($proposal, mediaUsages: $usages, relationReader: $reader);

        self::assertTrue($service->check($proposal->id)->ready);

        $stale = new Proposal(self::ID, self::SUBJECT, 'replace', array_replace($proposal->payload, ['expected_usage_revision' => 3]), 'media-usage-content-stale', 1, 'media-usage-dependency', ProposalState::APPROVED, targetUuid: null, entityType: 'media');
        self::assertContains('TARGET_REVISION_CHANGED', $this->service($stale, mediaUsages: $usages, relationReader: $reader)->check($stale->id)->reasons);
    }

    public function test_evidence_requires_claim_and_source_revision_binding_and_blocks_drift(): void
    {
        $claim = '33333333-3333-4333-8333-333333333333';
        $source = '44444444-4444-4444-8444-444444444444';
        $payload = ['claim_uuid' => $claim, 'source_uuid' => $source, 'claim_revision' => 4, 'source_revision' => 7, 'dependency_revisions' => [$claim => 4, $source => 7], 'excerpt' => 'Excerpt', 'relation' => 'supports'];
        $proposal = new Proposal(self::ID, '55555555-5555-4555-8555-555555555555', 'create', $payload, 'evidence-content', null, 'evidence-dependency', ProposalState::APPROVED, idempotencyKey: 'evidence-create', entityType: 'evidence');
        $reader = new class($claim, $source) implements EligibilityReader {
            public function __construct(private string $claim, private string $source) {}
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return $targetUuid === $this->claim ? 4 : ($targetUuid === $this->source ? 7 : 1); }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        self::assertTrue($this->service($proposal, relationReader: $reader)->check($proposal->id)->ready);
        $staleReader = new class($claim, $source) implements EligibilityReader {
            public function __construct(private string $claim, private string $source) {}
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return $targetUuid === $this->claim ? 4 : ($targetUuid === $this->source ? 8 : 1); }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        self::assertContains('SOURCE_REVISION_CHANGED', $this->service($proposal, relationReader: $staleReader)->check($proposal->id)->reasons);
    }

    private function service(Proposal $proposal, ?SubjectResolutionService $subjectResolver = null, ?EligibilityReader $relationReader = null, ?MediaUsageRepository $mediaUsages = null): ProposalEligibilityService
    {
        $repository = new class($proposal) implements ProposalRepository {
            public function __construct(private Proposal $proposal) {}
            public function create(Proposal $proposal): Proposal { return $proposal; }
            public function find(string $id): ?Proposal { return $id === $this->proposal->id ? $this->proposal : null; }
            public function findByIdempotencyKey(string $key): ?Proposal { return null; }
            public function save(Proposal $proposal): Proposal { return $this->proposal = $proposal; }
            public function findForUpdate(string $id): ?Proposal { return $this->find($id); }
            public function recordApproval(Proposal $proposal, string $actor): void {}
            public function latestApproval(string $proposalId): ?array { return ['proposal_revision' => $this->proposal->revision, 'fingerprint' => hex2bin($this->proposal->bindingFingerprint())]; }
            public function findLatestVideoIngest(string $videoId): ?Proposal { return null; }
        };
        $videos = new class implements VideoRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Video\Video { return null; }
            public function findByExternalReference(string $platform, string $externalId): ?\NHK\Core\Domain\Video\Video { return null; }
            public function create(\NHK\Core\Domain\Video\Video $video): \NHK\Core\Domain\Video\Video { return $video; }
            public function update(\NHK\Core\Domain\Video\Video $video, int $expectedRevision): \NHK\Core\Domain\Video\Video { return $video; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $authority = new class implements AuthorityRepository {
            public function findByCanonicalId(string $id): ?AuthorityEntity { return new AuthorityEntity($id, 'variant', 'nhk:variant:odo.36.8', 'Odo 36/8', 1, [], revision: 2); }
            public function findByStableKey(string $type, string $key): ?AuthorityEntity { return null; }
            public function create(AuthorityEntity $entity): AuthorityEntity { return $entity; }
            public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { return $entity; }
            public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { return $entity; }
            public function listByType(string $type, bool $includeRetired = false): array { return []; }
        };
        $claims = new class implements KnowledgeRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Knowledge\KnowledgeClaim { return null; }
            public function findByStableKey(string $stableKey): ?\NHK\Core\Domain\Knowledge\KnowledgeClaim { return null; }
            public function create(\NHK\Core\Domain\Knowledge\KnowledgeClaim $claim): \NHK\Core\Domain\Knowledge\KnowledgeClaim { return $claim; }
            public function update(\NHK\Core\Domain\Knowledge\KnowledgeClaim $claim, int $expectedRevision): \NHK\Core\Domain\Knowledge\KnowledgeClaim { return $claim; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $sources = new class implements SourceRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Knowledge\Source { return null; }
            public function findByStableKey(string $stableKey): ?\NHK\Core\Domain\Knowledge\Source { return null; }
            public function create(\NHK\Core\Domain\Knowledge\Source $source): \NHK\Core\Domain\Knowledge\Source { return $source; }
            public function update(\NHK\Core\Domain\Knowledge\Source $source, int $expectedRevision): \NHK\Core\Domain\Knowledge\Source { return $source; }
            public function list(bool $includeRetired = false): array { return []; }
        };
        $evidence = new class implements EvidenceRepository {
            public function findByCanonicalId(string $id): ?\NHK\Core\Domain\Knowledge\Evidence { return null; }
            public function create(\NHK\Core\Domain\Knowledge\Evidence $evidence): \NHK\Core\Domain\Knowledge\Evidence { return $evidence; }
            public function update(\NHK\Core\Domain\Knowledge\Evidence $evidence, int $expectedRevision): \NHK\Core\Domain\Knowledge\Evidence { return $evidence; }
            public function listByClaim(string $claimId, bool $includeRetired = false): array { return []; }
            public function listBySource(string $sourceId, bool $includeRetired = false): array { return []; }
        };
        $reader = $relationReader ?? new class implements EligibilityReader {
            public function isApplied(string $dependencyUuid): bool { return true; }
            public function targetRevision(string $targetUuid): ?int { return 1; }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('variant', 1, true, []));
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('variant', new AuthorityEndpointResolver($types, $authority));
        $evaluator = new VideoProposalEligibilityEvaluator($videos, $endpoints, new PredicateRegistry(), new CanonicalDependencyValidator($claims, $sources, $evidence), $subjectResolver);
        return new ProposalEligibilityService($repository, new DependencyGraph(new class implements DependencyRepository { public function directDependencies(string $proposalId): array { return []; } public function add(string $proposalId, string $dependencyUuid): void {} }), $reader, $evaluator, null, $mediaUsages);
    }

    private function proposal(array $metadata): Proposal
    {
        return new Proposal(self::ID, self::SUBJECT, 'ingest', ['canonical_id' => self::SUBJECT, 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'metadata' => $metadata], 'content-' . self::ID, null, 'dependency-' . self::ID, ProposalState::APPROVED, idempotencyKey: 'video-' . self::ID, entityType: 'video');
    }
}
