<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Capture\{ClockTypeShadowClassifier, EditorialCaptureCoordinator};
use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Graph\{ClassifiedAsPolicy, GraphService};
use NHK\Core\Application\Semantic\{ArticleComposer, ClaimRetrievalEngine, SubjectResolutionService, TextInputInterpreter};
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, NodeReference, PredicateRegistry};
use NHK\Core\Infrastructure\Graph\{GraphClockTypeCanonicalMembershipReader, InMemoryAuditSink};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryGraphRepository;
use PHPUnit\Framework\TestCase;

final class CaptureClockTypeShadowIntegrationTest extends TestCase
{
    public function test_production_editorial_capture_exposes_shadow_packet_without_changing_primary_subject(): void
    {
        $variant = $this->entity('variant', 'Odo Variant');
        $clock = $this->entity('classification', 'Đồng hồ vai bò', ['family' => 'clock_type']);
        $authority = new CaptureAuthorityRepository([$variant, $clock]);
        $graphRepository = new InMemoryGraphRepository();
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('variant', new FakeEndpointResolver('variant', [$variant->canonicalId]));
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$clock->canonicalId]));
        $graph = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new InMemoryAuditSink(), classifiedAs: new ClassifiedAsPolicy());
        $graph->create(new NodeReference('variant', $variant->canonicalId), 'classified_as', new NodeReference('classification', $clock->canonicalId));

        $result = $this->coordinator($authority, new GraphClockTypeCanonicalMembershipReader($graph, $authority))->execute([
            'idempotency_key' => 'capture-shadow-production-path',
            'text' => 'Odo, đây là Đồng hồ vai bò.',
            'subject_hints' => [$variant->canonicalId],
            'brand_context' => ['name' => 'Odo'],
        ]);

        $shadow = $result->diagnostics['semantic_diagnostics']['clock_type_shadow'];
        self::assertSame('RESOLVED_CANONICAL', $shadow['status']);
        self::assertSame($clock->canonicalId, $shadow['selected_candidate']['classification_uuid']);
        self::assertSame($variant->canonicalId, $shadow['canonical_subject']['id']);
        self::assertSame('variant', $shadow['canonical_subject']['type']);
        self::assertTrue($shadow['shadow_only']);
        self::assertSame([], $shadow['writes']);
        self::assertSame(1, count($graphRepository->allEdges()));
    }

    public function test_brandless_and_brand_only_capture_remain_valid_without_invented_brand_or_clock_type(): void
    {
        $specimen = $this->entity('specimen', 'Specimen X');
        $clock = $this->entity('classification', 'Đồng hồ công cộng', ['family' => 'clock_type']);
        $authority = new CaptureAuthorityRepository([$specimen, $clock]);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('specimen', new FakeEndpointResolver('specimen', [$specimen->canonicalId]));
        $endpoints->register('classification', new FakeEndpointResolver('classification', [$clock->canonicalId]));
        $graph = new GraphService(new InMemoryGraphRepository(), $endpoints, new PredicateRegistry(), new InMemoryAuditSink());
        $reader = new GraphClockTypeCanonicalMembershipReader($graph, $authority);

        $brandless = $this->coordinator($authority, $reader)->execute([
            'idempotency_key' => 'capture-shadow-brandless',
            'text' => 'Đồng hồ công cộng không rõ hãng.',
            'subject_hints' => [$specimen->canonicalId],
        ]);
        self::assertSame('RESOLVED_EXPLICIT', $brandless->diagnostics['semantic_diagnostics']['clock_type_shadow']['status']);
        self::assertSame([], $brandless->diagnostics['semantic_diagnostics']['clock_type_shadow']['writes']);

        $brandOnly = $this->coordinator($authority, $reader)->execute([
            'idempotency_key' => 'capture-shadow-brand-only',
            'text' => 'Odo',
            'subject_hints' => [$specimen->canonicalId],
            'brand_context' => ['name' => 'Odo'],
        ]);
        self::assertSame('NONE', $brandOnly->diagnostics['semantic_diagnostics']['clock_type_shadow']['status']);
        self::assertContains('BRAND_NOT_CLASSIFICATION_EVIDENCE', $brandOnly->diagnostics['semantic_diagnostics']['clock_type_shadow']['diagnostics']);
    }

    private function coordinator(CaptureAuthorityRepository $authority, GraphClockTypeCanonicalMembershipReader $reader): EditorialCaptureCoordinator
    {
        return new EditorialCaptureCoordinator(
            $captures = new CaptureShadowRepository(),
            static fn (array $input): array => ['items' => []],
            static fn (array $input): array => ['post_id' => 991, 'state_token' => 'capture-shadow-token'],
            new TextInputInterpreter(),
            new SubjectResolutionService(static function (string $hint) use ($authority): array {
                $entity = $authority->findByCanonicalId($hint);
                return $entity instanceof AuthorityEntity ? [['id' => $entity->canonicalId, 'type' => $entity->entityType, 'stable_key' => $entity->stableKey, 'name' => $entity->canonicalName, 'revision' => $entity->revision]] : [];
            }),
            new ClaimRetrievalEngine(static fn (array $subject): array => ['status' => 'available', 'items' => []], static fn (array $subject, array $neighborhood): array => []),
            static fn (array $context): array => ['status' => 'REVIEW_REQUIRED', 'writes' => []],
            new ArticleComposer(),
            static fn (array $context): array => ['status' => 'RECONCILED'],
            static fn (array $context): array => ['eligible' => false, 'blockers' => ['OWNER_PUBLICATION_REQUIRED']],
            static fn (array $context): array => ['status' => 'verified'],
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new ClockTypeShadowClassifier($authority, new EntityProfileResolver(), $reader),
        );
    }

    private function entity(string $type, string $name, array $payload = []): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), $type, $type . ':capture-' . random_int(1000, 9999), $name, 1, $payload, AuthorityState::ACTIVE);
    }
}

final class CaptureShadowRepository implements CaptureRepository
{
    /** @var array<string,CaptureRecord> */
    private array $records = [];
    public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
    public function findById(string $captureId): ?CaptureRecord { foreach ($this->records as $record) if ($record->captureId === $captureId) return $record; return null; }
    public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
}

final class CaptureAuthorityRepository implements AuthorityRepository
{
    /** @param list<AuthorityEntity> $entities */
    public function __construct(private array $entities) {}
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->canonicalId === $id) return $entity; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->entityType === $type && $entity->stableKey === $key) return $entity; return null; }
    public function create(AuthorityEntity $entity): AuthorityEntity { throw new \LogicException('shadow path must not write Authority'); }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { throw new \LogicException('shadow path must not write Authority'); }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { throw new \LogicException('shadow path must not write Authority'); }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->entities, static fn (AuthorityEntity $entity): bool => $entity->entityType === $type && ($includeRetired || $entity->active()))); }
}
