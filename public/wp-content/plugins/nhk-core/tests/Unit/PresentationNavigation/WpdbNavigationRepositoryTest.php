<?php
declare(strict_types=1);

namespace NHK\Tests\Unit\PresentationNavigation;

use NHK\Core\Domain\PresentationNavigation\NavigationNode;
use NHK\Core\Infrastructure\Presentation\WpdbNavigationRepository;
use PHPUnit\Framework\TestCase;

final class WpdbNavigationRepositoryTest extends TestCase
{
    public function test_missing_schema_is_unavailable_not_empty(): void
    {
        $repository = new WpdbNavigationRepository(new NavigationWpdbStub(false));
        $this->expectExceptionMessage('PRESENTATION_NAVIGATION_STORAGE_UNAVAILABLE');
        $repository->list('clock_type');
    }

    public function test_invalid_canonical_target_is_rejected_before_write(): void
    {
        $repository = new WpdbNavigationRepository(new NavigationWpdbStub(true));
        $this->expectExceptionMessage('NAVIGATION_NODE_INVALID');
        $repository->save(NavigationNode::fromArray([
            'id' => '', 'navigation_key' => 'clock_type', 'canonical_type' => 'classification',
            'canonical_uuid' => 'not-a-uuid', 'sort_order' => 1, 'enabled' => true,
        ]), 0);
    }

    public function test_revision_conflict_is_fail_closed(): void
    {
        $repository = new WpdbNavigationRepository(new NavigationWpdbStub(true, 0));
        $this->expectExceptionMessage('NAVIGATION_REVISION_CONFLICT');
        $repository->save(NavigationNode::fromArray([
            'id' => '4', 'navigation_key' => 'clock_type', 'canonical_type' => 'classification',
            'canonical_uuid' => '00000000-0000-4000-8000-000000000004', 'sort_order' => 1, 'enabled' => true,
        ]), 2);
    }
}

final class NavigationWpdbStub
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;

    public function __construct(private bool $schema, private int $queryResult = 0) {}
    public function prepare(string $query, mixed ...$args): string { return $query; }
    public function get_var(string $query): ?string { return $this->schema ? 'wp_nhk_presentation_navigation' : null; }
    public function get_results(string $query, mixed $mode = null): array { return []; }
    public function get_row(string $query, mixed $mode = null): ?array { return null; }
    public function query(string $query): int { return $this->queryResult; }
}
