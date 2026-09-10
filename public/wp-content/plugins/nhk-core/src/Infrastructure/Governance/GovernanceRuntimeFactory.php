<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Governance;

use NHK\Core\Application\Authority\{AuthorityService, SemanticMergeService};
use NHK\Core\Application\Collector\CollectorFacetMaintenanceExecutor;
use NHK\Core\Application\Governance\{AuthorityProposalExecutor, CanonicalApplyReadBackVerifier, ControlledApplyService, GovernanceService, ProposalEligibilityService, WordPressGovernanceAuthorizer};
use NHK\Core\Application\Graph\GraphService;
use NHK\Core\Application\Knowledge\{CanonicalDependencyValidator, KnowledgeService};
use NHK\Core\Application\Media\{MediaIngestGateway, MediaService};
use NHK\Core\Application\Video\{HistoricalVideoRelationEvidenceReconciliation, VideoCompletenessPolicy, VideoService};
use NHK\Core\Contracts\Governance\ProposalRepository;
use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Domain\Governance\DependencyGraph;
use NHK\Core\Infrastructure\Authority\{WpdbAuthorityRepository, WpdbSemanticMergeReceiptRepository};
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, SemanticMergeGraphAdapter, WpdbAuditSink as GraphAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Governance\WpdbAuditSink as GovernanceAuditSink;
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository, WordPressMediaAttachmentBridge};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;

final class GovernanceRuntimeFactory
{
    public static function fromWordPress(object $wpdb): GovernanceRuntime
    {
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new WpdbAuthorityRepository($wpdb);
        $media = new WpdbMediaRepository($wpdb);
        $assets = new WpdbMediaAssetRepository($wpdb);
        $usages = new WpdbMediaUsageRepository($wpdb);
        $videos = new WpdbVideoRepository($wpdb);
        $claims = new WpdbKnowledgeRepository($wpdb);
        $sources = new WpdbSourceRepository($wpdb);
        $evidence = new WpdbEvidenceRepository($wpdb);
        $endpoints = new EndpointTypeRegistry();
        CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence);
        $graphRepository = new WpdbGraphRepository($wpdb);
        $graphService = new GraphService($graphRepository, $endpoints, new PredicateRegistry(), new GraphAuditSink());
        $proposalRepository = new WpdbProposalRepository($wpdb);
        $governanceAudit = new GovernanceAuditSink($wpdb);
        $transactionManager = new WpdbTransactionManager($wpdb);
        $governance = new GovernanceService($proposalRepository, $governanceAudit, $transactionManager, new WordPressGovernanceAuthorizer());
        $eligibility = new ProposalEligibilityService($proposalRepository, new DependencyGraph(new WpdbDependencyRepository($wpdb)), new WpdbEligibilityReader($authority, $proposalRepository, $graphRepository, $media, $videos, $claims, $sources, $evidence));
        $authorityService = new AuthorityService($authority, $types, new \NHK\Core\Infrastructure\Authority\WpdbAuditSink($governanceAudit));
        $mediaService = new MediaService($media, $assets, $usages);
        $attachmentBridge = new WordPressMediaAttachmentBridge($wpdb, $mediaService, $media, $assets);
        $merge = new SemanticMergeService($authority, [new SemanticMergeGraphAdapter($graphService)], static function (string $event, object $receipt) use ($governanceAudit): void {
            $governanceAudit->recordEvent($event, 'semantic_merge', (string) ($receipt->idempotencyKey ?? ''), null, $receipt->toArray());
        }, new WpdbSemanticMergeReceiptRepository($wpdb));
        $dependencyValidator = new CanonicalDependencyValidator($claims, $sources, $evidence);
        $canonicalReadBack = new CanonicalApplyReadBackVerifier(static function (string $entityType, string $id) use ($authority, $media, $videos, $claims, $sources, $evidence, $graphRepository): ?array {
            $record = match ($entityType) {
                'knowledge' => $claims->findByCanonicalId($id),
                'source' => $sources->findByCanonicalId($id),
                'evidence' => $evidence->findByCanonicalId($id),
                'video' => $videos->findByCanonicalId($id),
                'media' => $media->findByCanonicalId($id),
                'relation' => $graphRepository->findByUuid($id),
                default => $authority->findByCanonicalId($id),
            };
            if ($record === null) return null;
            $actualType = property_exists($record, 'entityType') ? (string) $record->entityType : $entityType;
            $active = method_exists($record, 'active') ? (bool) $record->active() : (bool) ($record->active ?? (method_exists($record, 'isActive') ? $record->isActive() : false));
            $canonicalId = (string) ($record->canonicalId ?? $record->edge_uuid ?? '');
            return ['entity_type' => $actualType, 'canonical_id' => $canonicalId, 'active' => $active, 'revision' => (int) ($record->revision ?? 0), 'snapshot' => get_object_vars($record)];
        });
        $knowledgeService = new KnowledgeService($claims, $sources, $evidence);
        $historicalEvidence = new HistoricalVideoRelationEvidenceReconciliation($knowledgeService, $claims, $sources, $evidence, $proposalRepository);
        $collectorBranchReader = static function (string $classificationId) use ($authority, $claims, $graphService): array {
            $classification = $authority->findByCanonicalId($classificationId);
            if (!$classification instanceof \NHK\Core\Domain\Authority\AuthorityEntity || $classification->entityType !== 'classification' || !$classification->active()) return ['status' => 'unavailable', 'reason' => 'CLASSIFICATION_NOT_AVAILABLE'];
            $items = []; $after = 0;
            try {
                do {
                    $page = $graphService->findIncoming(new \NHK\Core\Domain\Graph\NodeReference('classification', $classificationId), 'about', $after, 200, false, 'knowledge');
                    foreach ((array) ($page['items'] ?? []) as $edge) {
                        if (!$edge instanceof \NHK\Core\Domain\Graph\GraphEdge || !$edge->isActive()) continue;
                        $claim = $claims->findByCanonicalId($edge->source->reference->endpoint_key);
                        if ($claim !== null && $claim->active && $claim->isPublic()) $items[$claim->canonicalId] = $claim;
                    }
                    $next = $page['next_cursor'] ?? null;
                    if ($next === null) break;
                    if (!is_int($next) || $next <= $after) return ['status' => 'unavailable', 'reason' => 'BRANCH_KNOWLEDGE_CURSOR_INVALID'];
                    $after = $next;
                } while (true);
            } catch (\Throwable) { return ['status' => 'unavailable', 'reason' => 'BRANCH_KNOWLEDGE_UNAVAILABLE']; }
            return ['status' => 'available', 'claims' => array_values($items), 'classification_revision' => $classification->revision];
        };
        $collectorExecutor = new CollectorFacetMaintenanceExecutor($knowledgeService, $collectorBranchReader);
        $controlledApply = new ControlledApplyService(
            $proposalRepository,
            new WpdbApplyAttemptRepository($wpdb),
            $transactionManager,
            new AuthorityProposalExecutor($authorityService, $graphService, $mediaService, new VideoService($videos), $knowledgeService, new MediaIngestGateway($mediaService, $attachmentBridge), $merge, dependencies: $dependencyValidator, completeness: new VideoCompletenessPolicy(), relationProposals: $proposalRepository, historicalEvidence: $historicalEvidence, collectorFacetExecutor: $collectorExecutor),
            $governanceAudit,
            $eligibility,
            new NoOpApplyExecutionHook(),
            new WordPressGovernanceAuthorizer(),
            $canonicalReadBack,
        );

        return new GovernanceRuntime($proposalRepository, $governance, $eligibility, $controlledApply);
    }
}
