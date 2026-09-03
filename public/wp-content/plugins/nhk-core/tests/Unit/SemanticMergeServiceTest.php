<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Authority\SemanticMergeService;
use NHK\Core\Contracts\Authority\{SemanticMergeReferenceAdapter,SemanticMergeReceiptRepository};
use NHK\Core\Domain\Authority\{AuthorityEntity,AuthorityState,EntityTypeDefinition,EntityTypeRegistry};
use NHK\Tests\Support\InMemoryAuthorityRepository;
use PHPUnit\Framework\TestCase;

final class SemanticMergeServiceTest extends TestCase
{
    private function service(array &$events = []): array
    {
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('component', 1, true, []));
        $repo = new InMemoryAuthorityRepository();
        $source = $repo->create(new AuthorityEntity('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f11', 'component', 'o-do.dial', 'Pinned', 1, []));
        $target = $repo->create(new AuthorityEntity('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f12', 'component', 'odo.dial', 'Pinned', 1, []));
        $adapter = new class implements SemanticMergeReferenceAdapter {
            public array $refs = [['reference' => 'edge-1'], ['reference' => 'edge-2']];
            public function enumerate(AuthorityEntity $source, AuthorityEntity $target): array { return $source->state === AuthorityState::RETIRED ? [] : $this->refs; }
            public function plan(AuthorityEntity $source, AuthorityEntity $target, array $references): array { return $references; }
            public function apply(array $planned): array { return ['action' => 'moved', 'reference' => (string) $planned['reference']]; }
            public function verify(array $planned): bool { return true; }
        };
        $stored = [];
        $repoStore = new class($stored) implements SemanticMergeReceiptRepository {
            public function __construct(private array &$stored) {}
            public function findByIdempotencyKey(string $key): ?\NHK\Core\Domain\Authority\SemanticMergeReceipt { return $this->stored[$key] ?? null; }
            public function append(\NHK\Core\Domain\Authority\SemanticMergeReceipt $receipt): void { $this->stored[$receipt->idempotencyKey] = $receipt; }
        };
        return [new SemanticMergeService($repo, [$adapter], $events === [] ? null : static function(string $event, object $receipt) use (&$events): void { $events[] = [$event, $receipt]; }, $repoStore), $repo, $source, $target];
    }

    public function test_same_type_merge_moves_references_retires_source_and_preserves_uuid(): void
    {
        [$service, $repo, $source, $target] = $this->service();
        $receipt = $service->merge($source->canonicalId, $target->canonicalId, 1, 1, 'merge-1');
        self::assertSame($source->canonicalId, $receipt->sourceUuid);
        self::assertSame($target->canonicalId, $receipt->targetUuid);
        self::assertSame(2, $receipt->moved);
        self::assertSame('retired', $receipt->sourceLifecycle);
        self::assertFalse($repo->findByCanonicalId($source->canonicalId)->active());
    }

    public function test_merge_replay_is_idempotent_and_changed_plan_conflicts(): void
    {
        [$service, , $source, $target] = $this->service();
        $first = $service->merge($source->canonicalId, $target->canonicalId, 1, 1, 'merge-2');
        self::assertSame($first, $service->merge($source->canonicalId, $target->canonicalId, 1, 1, 'merge-2'));
        $this->expectException(\RuntimeException::class);
        $service->merge($source->canonicalId, $target->canonicalId, 2, 1, 'merge-2');
    }

    public function test_cross_type_merge_fails_closed(): void
    {
        [$service, $repo, $source] = $this->service();
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $other = $repo->create(new AuthorityEntity('018f0f4e-7b4d-7c72-9b18-5c2b3f3d6f13', 'brand', 'brand', 'Brand', 1, []));
        $this->expectException(\InvalidArgumentException::class);
        $service->merge($source->canonicalId, $other->canonicalId, 1, 1, 'merge-3');
    }
}
