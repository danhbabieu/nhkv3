<?php
declare(strict_types=1);

namespace NHK\Core\Application\Projection;

final class ProjectionEventSubscriber
{
    public function __construct(private ProjectionInvalidationService $invalidation) {}

    public function register(): void
    {
        if (!function_exists('add_action')) return;
        foreach (['nhk_knowledge_approved', 'nhk_knowledge_revised', 'nhk_knowledge_superseded', 'nhk_knowledge_deprecated'] as $event) add_action($event, [$this, 'claim']);
        foreach (['nhk_graph_edge_created', 'nhk_graph_edge_removed', 'nhk_graph_edge_retired', 'nhk_graph_edge_reactivated'] as $event) add_action($event, [$this, 'relation']);
        add_action('nhk_source_visibility_changed', fn (string $id): array => $this->dependency('source', $id));
        add_action('nhk_evidence_visibility_changed', fn (string $id): array => $this->dependency('evidence', $id));
    }

    public function claim(string $id): array { return $this->invalidation->invalidateClaim($id); }
    public function relation(string $id): array { return $this->invalidation->invalidateRelation($id); }
    public function dependency(string $kind, string $id): array { return $this->invalidation->invalidate($kind, $id); }
}
