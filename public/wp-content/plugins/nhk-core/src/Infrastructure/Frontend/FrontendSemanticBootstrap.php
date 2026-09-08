<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Frontend;

use NHK\Core\Application\Entity\{EntityMediaProjection, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, SemanticDossierCoverageAudit, SemanticDossierQuery};
use NHK\Core\Application\Graph\{GraphService, PredicateTraversalPolicy, RelatedSemanticQuery, StructuralContextQuery};
use NHK\Core\Application\Knowledge\{EntityKnowledgeProjection, KnowledgePageQuery};
use NHK\Core\Application\Projection\{ClaimProjectionService, ClaimScopeResolver, GraphProjectionPolicy, LiveLedgerProjectionBuilder, ProjectionEventSubscriber, ProjectionInvalidationService};
use NHK\Core\Application\Media\{PublicMediaAssetDelivery, PublicMediaGalleryQuery};
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Admin\SemanticDossierCoverageAdminPage;
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Infrastructure\Projection\{WpdbProjectionDependencyIndex, WpdbProjectionRevisionStore, WpdbProjectionSchema};
use NHK\Core\Infrastructure\Http\ProjectionAdminApi;
use NHK\Core\Shared\Migration\MigrationStatus;

/**
 * Read-only public projection wiring. It never writes Authority, Graph,
 * Knowledge, Media or Video state.
 */
final class FrontendSemanticBootstrap
{
    public static function boot(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !function_exists('add_filter')) return;

        $status = new MigrationStatus();
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        $authority = new WpdbAuthorityRepository($wpdb);
        $media = new WpdbMediaRepository($wpdb);
        $assets = new WpdbMediaAssetRepository($wpdb);
        $usages = new WpdbMediaUsageRepository($wpdb);
        $videos = new WpdbVideoRepository($wpdb);
        $claims = new WpdbKnowledgeRepository($wpdb);
        $evidence = new WpdbEvidenceRepository($wpdb);
        $sources = new WpdbSourceRepository($wpdb);

        $endpoints = new EndpointTypeRegistry();
        CoreEndpointResolverRegistrar::register($endpoints, $types, $authority, $media, $videos, $claims, $sources, $evidence);
        $predicates = new PredicateRegistry();
        $graph = new GraphService(new WpdbGraphRepository($wpdb), $endpoints, $predicates, new WpdbAuditSink());
        $contexts = new StructuralContextQuery($graph, $authority);
        $routes = new PublicRouteResolver($authority, $types, $contexts);
        $eligibility = new PublicEntityEligibilityPolicy($authority, $types, $routes, $contexts);

        $claimResolver = new ClaimScopeResolver(
            $claims,
            $graph,
            new GraphProjectionPolicy($predicates),
            evidence: $evidence,
            sources: $sources,
            labelResolver: static function (\NHK\Core\Domain\Graph\NodeReference $reference) use ($authority): ?string {
                $entity = $authority->findByCanonicalId($reference->endpoint_key);
                return $entity?->canonicalName;
            },
        );
        $projectionSchema = new WpdbProjectionSchema($wpdb);
        $projectionStore = new WpdbProjectionRevisionStore($wpdb, $projectionSchema);
        $projectionDependencies = new WpdbProjectionDependencyIndex($wpdb, $projectionSchema);
        $claimProjection = new ClaimProjectionService(new LiveLedgerProjectionBuilder($claimResolver, evidence: $evidence, sources: $sources), $projectionStore, dependencies: $projectionDependencies);
        (new ProjectionEventSubscriber(new ProjectionInvalidationService($projectionDependencies, $projectionStore, $claimProjection, static fn (string $claimId): array => $claimResolver->impactNodesForClaim($claimId), static fn (string $edgeUuid): array => $claimResolver->impactNodesForRelation($edgeUuid))))->register();
        $projectionAdmin = new ProjectionAdminApi($claimProjection);
        add_action('rest_api_init', [$projectionAdmin, 'register']);

        $gallery = new PublicMediaGalleryQuery($media, $assets, PublicMediaAssetDelivery::fromEnvironment($assets, $media), $usages);
        $entityMedia = new EntityMediaProjection($media, $assets, $usages);
        $entityKnowledge = new EntityKnowledgeProjection($claims, $evidence, $sources, $status);
        $knowledgeArchive = new KnowledgePageQuery($claims, $evidence, $sources, $status);
        $postProjector = static function(int $postId): ?array {
            if ($postId < 1 || !function_exists('get_post') || !function_exists('get_post_status') || !function_exists('get_permalink') || !function_exists('get_the_title')) return null;
            $post = get_post($postId);
            if (!$post instanceof \WP_Post || get_post_status($post) !== 'publish') return null;
            return [
                'title' => (string) get_the_title($post),
                'url' => (string) get_permalink($post),
                'excerpt' => function_exists('get_the_excerpt') ? (string) get_the_excerpt($post) : '',
            ];
        };
        $dossier = new SemanticDossierQuery(
            $authority,
            $types,
            new PublicIdentityContract($types),
            $eligibility,
            $routes,
            new RelatedSemanticQuery($graph, new PredicateTraversalPolicy($predicates)),
            $entityKnowledge,
            $entityMedia,
            $media,
            $videos,
            $postProjector,
            $gallery,
        );
        $coverageAudit = new SemanticDossierCoverageAudit($types, $authority, static fn(AuthorityEntity $entity): array => $dossier->forEntity($entity));
        (new SemanticDossierCoverageAdminPage($coverageAudit))->register();

        add_filter('nhk_v3_home_semantic_modules', static function(array $modules) use ($status, $gallery, $knowledgeArchive): array {
            if ($status->mediaStorageReady()) $modules['media'] = $gallery->archive(1, 8)['items'];
            if ($status->knowledgeStorageReady()) {
                $modules['knowledge'] = [];
                foreach (($knowledgeArchive->archive(1, 6)['items'] ?? []) as $item) {
                    if (!is_array($item) || trim((string) ($item['text'] ?? '')) === '') continue;
                    $modules['knowledge'][] = ['text' => (string) $item['text'], 'type' => (string) ($item['type'] ?? '')];
                }
            }
            return $modules;
        }, 20, 1);

        add_filter('nhk_v3_entity_detail_projection', static function(array $item, object $entity) use ($dossier, $claimProjection): array {
            if ($entity instanceof AuthorityEntity) {
                $item['dossier'] = $dossier->forEntity($entity);
                $item['claim_projection'] = $claimProjection->getLedger($entity->canonicalId, ['node_type' => $entity->entityType]);
                $published = $claimProjection->getPublishedSeoProjection($entity->canonicalId);
                if (is_array($published)) $item['published_claim_projection'] = $published;
            }
            return $item;
        }, 10, 2);

        add_filter('nhk_v3_post_dossier', static function(array $value, int $postId) use ($dossier): array {
            if ($postId < 1) return $value;
            $projection = $dossier->forPost($postId);
            return is_array($projection) ? $projection : $value;
        }, 10, 2);

        add_filter('nhk_v3_article_media_gallery', static function(array $value, int $postId) use ($entityMedia): array {
            if ($postId < 1) return $value;
            $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
            $projection = $entityMedia->forEntity('wp_post', $blogId . ':' . $postId);
            return is_array($projection) ? $projection : $value;
        }, 10, 2);
    }
}
