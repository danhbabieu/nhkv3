<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Frontend;

use NHK\Core\Application\Entity\{BrandDossierProjection, EntityMediaProjection, PublicEntityEligibilityPolicy, PublicIdentityContract, PublicRouteResolver, SemanticDossierQuery};
use NHK\Core\Application\Graph\{BrandAggregationQuery, GraphService, PredicateTraversalPolicy, RelatedSemanticQuery, StructuralContextQuery};
use NHK\Core\Application\Knowledge\EntityKnowledgeProjection;
use NHK\Core\Application\Media\PublicMediaGalleryQuery;
use NHK\Core\Domain\Authority\{AuthorityEntity, CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, PredicateRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Graph\{CoreEndpointResolverRegistrar, WpdbAuditSink, WpdbGraphRepository};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Infrastructure\Video\WpdbVideoRepository;
use NHK\Core\Shared\Migration\MigrationStatus;

/**
 * Registers detail-only public dossier projection on the established entity
 * enrichment hook. No archive query, semantic owner, or persistence path is
 * changed by this bootstrap.
 */
final class EntityDossierBootstrap
{
    public static function boot(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !function_exists('add_filter')) return;

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
        $predicates = new PredicateRegistry();
        $graph = new GraphService(new WpdbGraphRepository($wpdb), $endpoints, $predicates, new WpdbAuditSink());
        $contexts = new StructuralContextQuery($graph, $authority);
        $routes = new PublicRouteResolver($authority, $types, $contexts);
        $eligibility = new PublicEntityEligibilityPolicy($authority, $types, $routes, $contexts);
        $brandAggregation = new BrandAggregationQuery($graph, $authority, $types, $routes, $eligibility);
        $entityMedia = new EntityMediaProjection($media, $assets, $usages);
        $entityKnowledge = new EntityKnowledgeProjection($claims, $evidence, $sources, new MigrationStatus());
        $relations = new RelatedSemanticQuery($graph, new PredicateTraversalPolicy($predicates));
        $dossier = new SemanticDossierQuery(
            $authority,
            $types,
            new PublicIdentityContract($types),
            $eligibility,
            $routes,
            $relations,
            $entityKnowledge,
            $entityMedia,
            $media,
            $videos,
            null,
            new PublicMediaGalleryQuery($media, $assets),
        );
        $brandProjection = new BrandDossierProjection();

        add_filter('nhk_v3_entity_detail_projection', static function (array $value, AuthorityEntity $entity) use ($dossier, $brandAggregation, $brandProjection): array {
            $value['dossier'] = $dossier->forEntity($entity);
            if ($entity->entityType === 'brand' && ($value['dossier']['status'] ?? '') === 'AVAILABLE') {
                $value['dossier'] = $brandProjection->merge($value['dossier'], $brandAggregation->forBrand($entity->canonicalId));
            }
            return $value;
        }, 10, 2);
    }
}
