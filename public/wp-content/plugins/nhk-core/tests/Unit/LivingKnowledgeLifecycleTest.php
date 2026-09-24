<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Knowledge\LivingKnowledgeLifecycleService;
use NHK\Core\Domain\Projection\{ProjectionRevision, ProjectionStatus};
use NHK\Core\Infrastructure\Projection\{InMemoryProjectionDependencyIndex, InMemoryProjectionRevisionStore};
use PHPUnit\Framework\TestCase;

final class LivingKnowledgeLifecycleTest extends TestCase
{
    public function test_revision_change_discovers_multiple_owner_types_without_rewriting_them(): void
    {
        [$service, $index, $store] = $this->serviceWithOwners();
        $result = $service->knowledgeChanged('claim', 'claim-1', 2);

        self::assertSame('EDITORIAL_REGENERATION_AVAILABLE', $result['status']);
        $owners = array_column($result['owners'], 'owner_id');
        sort($owners);
        self::assertSame(['article-1', 'media-1', 'video-1'], $owners);
        self::assertSame('STALE', $service->state('article-1')['state']);
        self::assertSame(1, $store->findCandidate('article-1')->revision);
        self::assertSame(1, $index->findByDependency('claim', 'claim-1')[0]['dependency_revision']);
    }

    public function test_only_consumed_dependencies_are_registered_and_successful_readback_updates_revision(): void
    {
        [$service, $index] = $this->serviceWithOwners();
        $service->registerConsumed(['id' => 'future-1', 'type' => 'future_owner'], [['kind' => 'claim', 'id' => 'claim-selected', 'revision' => 4, 'surface' => 'summary', 'trace' => ['claim_id' => 'claim-selected']]]);
        self::assertSame(['claim-selected'], array_column($index->findByDependency('claim', 'claim-selected'), 'id'));
        self::assertSame([], $index->findByDependency('claim', 'claim-unselected'));

        $changed = $service->knowledgeChanged('claim', 'claim-selected', 5);
        self::assertSame('EDITORIAL_REGENERATION_AVAILABLE', $changed['status']);
        $blocked = $service->reconcileReadback(['id' => 'future-1', 'type' => 'future_owner'], ['status' => 'unknown']);
        self::assertSame('STALE', $blocked['state']);
        $current = $service->reconcileReadback(['id' => 'future-1', 'type' => 'future_owner'], ['status' => 'verified', 'dependencies' => [['kind' => 'claim', 'id' => 'claim-selected', 'revision' => 5, 'surface' => 'summary']]]);
        self::assertSame('CURRENT', $current['state']);
        self::assertSame(5, $index->findByDependency('claim', 'claim-selected')[0]['dependency_revision']);
    }

    public function test_regeneration_reenters_core_and_preview_reports_additions_removals_and_wording(): void
    {
        [$service] = $this->serviceWithOwners();
        $calls = [];
        $regenerated = $service->regenerate(['id' => 'article-1', 'type' => 'article'], ['subject' => 'subject'], static function (array $input) use (&$calls): array { $calls[] = 'core'; return ['pack' => ['latest' => true]]; }, static function (array $pack) use (&$calls): array { $calls[] = 'consumer'; return ['surfaces' => ['body' => 'new fact'], 'dependencies' => [['kind' => 'claim', 'id' => 'claim-2', 'revision' => 2]]]; });
        self::assertSame(['core', 'consumer'], $calls);
        self::assertSame('REGENERATION_PREVIEW_READY', $regenerated['status']);
        $preview = $service->preview(['id' => 'article-1', 'type' => 'article'], ['revision' => 1, 'surfaces' => ['body' => 'old fact'], 'dependencies' => [['kind' => 'claim', 'id' => 'claim-1', 'revision' => 1]]], ['revision' => 2, 'surfaces' => ['body' => 'new fact'], 'dependencies' => [['kind' => 'claim', 'id' => 'claim-2', 'revision' => 2]]]);
        self::assertSame(['claim-2'], $preview->toArray()['factual_additions']);
        self::assertSame(['claim-1'], $preview->toArray()['factual_removals']);
        self::assertTrue($preview->toArray()['governed_apply_required']);
    }

    private function serviceWithOwners(): array
    {
        $index = new InMemoryProjectionDependencyIndex();
        $store = new InMemoryProjectionRevisionStore();
        foreach (['article-1' => 'article', 'video-1' => 'video', 'media-1' => 'media'] as $id => $type) {
            $store->saveCandidate(new ProjectionRevision($id, 1, ProjectionStatus::CANDIDATE, hash('sha256', $id), str_repeat('b', 64), str_repeat('c', 64), payload: ['surfaces' => ['body' => 'current'] ]));
            $index->add(['kind' => 'claim', 'id' => 'claim-1', 'node_uuid' => $id, 'node_type' => $type, 'section_key' => $type === 'media' ? 'caption' : 'body', 'dependency_revision' => 1]);
        }
        return [new LivingKnowledgeLifecycleService($index, $store), $index, $store];
    }
}
