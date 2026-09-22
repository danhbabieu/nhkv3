<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\AuthorityIntentPlanner;
use NHK\Core\Application\Capture\AuthorityCaptureService;
use NHK\Core\Application\Graph\ExplicitRelationIntentPlanner;
use NHK\Core\Application\Graph\ClassifiedAsPolicy;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Authority\{AuthorityEntity, AuthorityState, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Capture\CaptureRecord;
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, NodeReference, PredicateRegistry};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class CaptureClassifiedAsRuntimeIntegrationTest extends TestCase
{
    public function test_capture_authority_plan_accepts_classified_as_with_target_family(): void
    {
        $plan = $this->plan(true);
        self::assertSame([], $plan['blockers']);
        self::assertCount(1, $plan['relation_candidates']);
        self::assertNotContains('CLASSIFICATION_SCOPE_UNSUPPORTED', $plan['blockers']);
    }

    public function test_capture_authority_plan_rejects_classification_without_family(): void
    {
        $plan = $this->plan(false);
        self::assertSame([], $plan['relation_candidates']);
        self::assertContains('CLASSIFICATION_FAMILY_REQUIRED', array_column($plan['blockers'], 'code'));
    }

    /** @return array<string,mixed> */
    private function plan(bool $withFamily): array
    {
        $sourceId = UuidCodec::newV7();
        $targetId = UuidCodec::newV7();
        $authority = new InMemoryAuthorityRepository();
        $authority->create(new AuthorityEntity($sourceId, 'variant', 'nhk:variant:runtime-test', 'Runtime Variant', 1, []));
        $authority->create(new AuthorityEntity($targetId, 'classification', 'nhk:classification:runtime-test', 'Runtime Classification', 1, $withFamily ? ['family' => 'clock_type'] : []));

        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('variant', new RuntimeAuthorityEndpointResolver('variant', [$sourceId => 1]));
        $endpoints->register('classification', new RuntimeAuthorityEndpointResolver('classification', [$targetId => 1]));
        $state = static function (NodeReference $reference) use ($authority): ?array {
            $record = $authority->findByCanonicalId($reference->endpoint_key);
            return $record === null ? null : ['active' => $record->active(), 'revision' => $record->revision, 'family' => $record->payload['family'] ?? null];
        };
        $relations = new ExplicitRelationIntentPlanner($endpoints, new PredicateRegistry(), $state, static fn (): array => [], new ClassifiedAsPolicy());
        $authorityPlanner = new AuthorityIntentPlanner($authority, $types, relationIntents: $relations);
        $captures = new class implements CaptureRepository {
            /** @var array<string,CaptureRecord> */
            private array $records = [];
            public function findByIdempotencyKey(string $key): ?CaptureRecord { return $this->records[$key] ?? null; }
            public function findById(string $captureId): ?CaptureRecord { foreach ($this->records as $record) if ($record->captureId === $captureId) return $record; return null; }
            public function create(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] ??= $record; }
            public function save(CaptureRecord $record): CaptureRecord { return $this->records[$record->idempotencyKey] = $record; }
        };
        $capture = new AuthorityCaptureService($captures, static fn (array $input, CaptureRecord $record): array => $authorityPlanner->plan($input, ['capture_id' => $record->captureId, 'capture_revision' => $record->revision]));

        $result = $capture->execute([
            'idempotency_key' => 'classified-as-runtime-integration',
            'purpose' => 'AUTHORITY',
            'text' => '',
            'authority_intent' => [
                'mode' => 'PLAN',
                'relation_intents' => [[
                    'source_type' => 'variant', 'source_uuid' => $sourceId,
                    'predicate' => 'classified_as', 'target_type' => 'classification',
                    'target_uuid' => $targetId, 'provenance' => 'EXPLICIT_USER_KNOWLEDGE',
                ]],
            ],
        ]);

        return $result->context['authority_plan'];
    }
}

final class RuntimeAuthorityEndpointResolver implements EndpointRevisionReader
{
    /** @param array<string,int> $revisions */
    public function __construct(private string $type, private array $revisions) {}
    public function supports(string $endpoint_type): bool { return $endpoint_type === $this->type; }
    public function exists(NodeReference $reference): bool { return isset($this->revisions[$reference->endpoint_key]); }
    public function normalize(NodeReference $reference): NodeReference { return new NodeReference($this->type, strtolower(trim($reference->endpoint_key))); }
    public function revision(NodeReference $reference): ?int { return $this->revisions[$reference->endpoint_key] ?? null; }
}
