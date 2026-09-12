<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\{AuthorityIntentPlanner, ClockTypeCreationLifecycle};
use NHK\Core\Application\Entity\EntityProfileResolver;
use NHK\Core\Application\Governance\GovernedAuthorityPlanExecutor;
use NHK\Core\Contracts\Authority\AuthorityCanonicalReader;
use NHK\Core\Contracts\Authority\AuthorityRepository;
use NHK\Core\Contracts\Governance\GovernedAuthorityPlanApplier;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Governance\ConversationalAuthorityPolicy;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ClockTypeCreationLifecycleTest extends TestCase
{
    public function test_plan_searches_exact_canonical_before_proposing_a_new_clock_type(): void
    {
        $existing = $this->entity('Đồng hồ công cộng', ['family' => 'clock_type']);
        $authority = new LifecycleAuthorityReader([$existing]);
        $lifecycle = $this->lifecycle($authority);

        $plan = $lifecycle->plan('Đồng hồ công cộng', [], ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1]);

        self::assertCount(1, $plan['reuse']);
        self::assertSame($existing->canonicalId, $plan['reuse'][0]['canonical_uuid']);
        self::assertSame([], $plan['create_candidates']);
        self::assertTrue($plan['lifecycle']['reuse_first']);
    }

    public function test_plan_candidate_has_canonical_family_and_provenance_shape(): void
    {
        $lifecycle = $this->lifecycle(new LifecycleAuthorityReader());

        $plan = $lifecycle->plan('Đồng hồ công cộng', ['description' => 'Loại công cộng'], ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1]);
        $candidate = $plan['create_candidates'][0];

        self::assertSame('classification', $candidate['entity_type']);
        self::assertSame('clock_type', $candidate['family']);
        self::assertSame('nhk:classification:clock-type.dong-ho-cong-cong', $candidate['proposed_stable_key']);
        self::assertSame('EXPLICIT_USER_KNOWLEDGE', $candidate['provenance']);
        self::assertSame('Loại công cộng', $candidate['description']);
        self::assertSame([], $candidate['ambiguities']);
        self::assertSame([], $candidate['blockers']);
    }

    public function test_apply_delegates_to_governed_executor_and_requires_canonical_readback(): void
    {
        $entity = $this->entity('Đồng hồ công cộng', ['family' => 'clock_type']);
        $authority = new LifecycleAuthorityReader();
        $applier = new RecordingPlanApplier([
            'status' => 'APPLIED',
            'apply_results' => [['canonical_readback' => ['canonical_id' => $entity->canonicalId, 'snapshot' => ['canonicalId' => $entity->canonicalId, 'payload' => ['family' => 'clock_type'], 'stableKey' => $entity->stableKey, 'canonicalName' => $entity->canonicalName]]]],
        ]);
        $lifecycle = $this->lifecycle($authority, $applier);
        $plan = $lifecycle->plan('Đồng hồ công cộng', [], ['capture_id' => UuidCodec::newV7(), 'capture_revision' => 1]);
        $authority->add($entity);

        $result = $lifecycle->apply($plan, $plan['plan_fingerprint'], [$plan['create_candidates'][0]['candidate_id']], ConversationalAuthorityPolicy::REVIEW_REQUIRED);

        self::assertSame('APPLIED', $result['status']);
        self::assertTrue($applier->called);
        self::assertSame('clock_type', $result['canonical_readback']['family']);
        self::assertSame([], $result['duplicate_verification']['duplicates']);
    }

    public function test_lifecycle_source_does_not_depend_on_authority_or_wordpress_writer(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Authority/ClockTypeCreationLifecycle.php');
        foreach (['AuthorityRepository', 'GraphService', 'wp_insert_post', 'INSERT ', 'UPDATE '] as $forbidden) self::assertStringNotContainsString($forbidden, $source, $forbidden);
    }

    private function lifecycle(LifecycleAuthorityReader $authority, ?RecordingPlanApplier $applier = null): ClockTypeCreationLifecycle
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        return new ClockTypeCreationLifecycle(
            new AuthorityIntentPlanner($authority, $types),
            $applier ?? new RecordingPlanApplier(['status' => 'APPLIED', 'apply_results' => []]),
            $authority,
            new EntityProfileResolver(),
        );
    }

    private function entity(string $name, array $payload): AuthorityEntity
    {
        return new AuthorityEntity(UuidCodec::newV7(), 'classification', 'nhk:classification:clock-type.' . UuidCodec::newV7(), $name, 1, $payload, AuthorityState::ACTIVE, 1);
    }
}

final class RecordingPlanApplier implements GovernedAuthorityPlanApplier
{
    public bool $called = false;
    public function __construct(private array $result) {}
    public function execute(array $plan, string $approvedFingerprint, string $currentFingerprint, array $approvedCandidateIds, ConversationalAuthorityPolicy $policy, string $actor = '0'): array
    {
        $this->called = true;
        return $this->result;
    }
}

final class LifecycleAuthorityReader implements AuthorityCanonicalReader, AuthorityRepository
{
    /** @param list<AuthorityEntity> $entities */
    public function __construct(private array $entities = []) {}
    public function add(AuthorityEntity $entity): void { $this->entities[] = $entity; }
    public function findByCanonicalId(string $id): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->canonicalId === $id) return $entity; return null; }
    public function findByStableKey(string $type, string $key): ?AuthorityEntity { foreach ($this->entities as $entity) if ($entity->entityType === $type && $entity->stableKey === $key) return $entity; return null; }
    public function listByType(string $type, bool $includeRetired = false): array { return array_values(array_filter($this->entities, static fn (AuthorityEntity $entity): bool => $entity->entityType === $type && ($includeRetired || $entity->active()))); }
    public function pageByType(string $type, int $limit = 100, ?string $after = null, bool $includeRetired = false): array { return ['items' => $this->listByType($type, $includeRetired), 'next_cursor' => null]; }
    public function create(AuthorityEntity $entity): AuthorityEntity { throw new \LogicException('read-only test reader'); }
    public function update(AuthorityEntity $entity, int $expectedRevision): AuthorityEntity { throw new \LogicException('read-only test reader'); }
    public function rekey(AuthorityEntity $entity, string $oldStableKey, string $newStableKey, int $expectedRevision): AuthorityEntity { throw new \LogicException('read-only test reader'); }
}
