<?php
declare(strict_types=1);

namespace NHK\Core\Application\Migration;

final class V2DomainPostClassifier
{
    /** @var array<string,array{classification:string,reason_code:string,target_boundary:string,identity_rule:string,relation_rule:string,migration_action:string,retirement_rule:string}> */
    private const CLASSIFICATIONS = [
        'nhk_brand' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING', 'target_boundary' => 'authority.brand', 'identity_rule' => 'legacy_post_id_to_canonical_uuid', 'relation_rule' => 'governed_about_edges_only', 'migration_action' => 'review_then_governed_mapping', 'retirement_rule' => 'retain_until_mapping_or_retirement_decision'],
        'nhk_model' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING', 'target_boundary' => 'authority.model', 'identity_rule' => 'legacy_post_id_to_canonical_uuid', 'relation_rule' => 'governed_about_edges_only', 'migration_action' => 'review_then_governed_mapping', 'retirement_rule' => 'retain_until_mapping_or_retirement_decision'],
        'nhk_variant' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING', 'target_boundary' => 'authority.variant', 'identity_rule' => 'legacy_post_id_to_canonical_uuid', 'relation_rule' => 'governed_about_edges_only', 'migration_action' => 'review_then_governed_mapping', 'retirement_rule' => 'retain_until_mapping_or_retirement_decision'],
        'nhk_movement' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING', 'target_boundary' => 'authority.movement', 'identity_rule' => 'legacy_post_id_to_canonical_uuid', 'relation_rule' => 'governed_about_edges_only', 'migration_action' => 'review_then_governed_mapping', 'retirement_rule' => 'retain_until_mapping_or_retirement_decision'],
        'nhk_music' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING', 'target_boundary' => 'authority.music', 'identity_rule' => 'legacy_post_id_to_canonical_uuid', 'relation_rule' => 'governed_about_edges_only', 'migration_action' => 'review_then_governed_mapping', 'retirement_rule' => 'retain_until_mapping_or_retirement_decision'],
        'nhk_component' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING', 'target_boundary' => 'authority.component', 'identity_rule' => 'legacy_post_id_to_canonical_uuid', 'relation_rule' => 'governed_about_edges_only', 'migration_action' => 'review_then_governed_mapping', 'retirement_rule' => 'retain_until_mapping_or_retirement_decision'],
        'nhk_classification' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'DOMAIN_IDENTITY_REQUIRES_EXPLICIT_MAPPING', 'target_boundary' => 'authority.classification', 'identity_rule' => 'legacy_post_id_to_canonical_uuid', 'relation_rule' => 'governed_about_edges_only', 'migration_action' => 'review_then_governed_mapping', 'retirement_rule' => 'retain_until_mapping_or_retirement_decision'],
        'nhk_knowledge' => ['classification' => 'STRUCTURE_REFERENCE', 'reason_code' => 'KNOWLEDGE_IDENTITY_REQUIRES_EXPLICIT_MAPPING', 'target_boundary' => 'knowledge.claim', 'identity_rule' => 'legacy_post_id_to_claim_uuid', 'relation_rule' => 'governed_claim_relations_only', 'migration_action' => 'review_then_governed_mapping', 'retirement_rule' => 'retain_until_mapping_or_retirement_decision'],
        'attachment' => ['classification' => 'REQUIRES_REVIEW', 'reason_code' => 'MEDIA_SOURCE_RECOVERY_OR_RETIREMENT_REQUIRED', 'target_boundary' => 'media.asset', 'identity_rule' => 'checksum_and_provenance_verified', 'relation_rule' => 'governed_media_usage_only', 'migration_action' => 'recover_verify_then_map_or_retire', 'retirement_rule' => 'retire_only_after_recovery_review'],
        'wp_global_styles' => ['classification' => 'RETIRE', 'reason_code' => 'NON_EDITORIAL_IMPLEMENTATION_RECORD', 'target_boundary' => 'none', 'identity_rule' => 'not_applicable', 'relation_rule' => 'no_semantic_relation', 'migration_action' => 'record_governed_retirement', 'retirement_rule' => 'permanent_non_editorial_retirement'],
        'nhk_article' => ['classification' => 'EDITORIAL_DEFERRED', 'reason_code' => 'EDITORIAL_POST_REQUIRES_SEPARATE_REVIEW', 'target_boundary' => 'wp_posts', 'identity_rule' => 'native_post_identity', 'relation_rule' => 'governed_post_semantic_links_only', 'migration_action' => 'separate_editorial_review', 'retirement_rule' => 'retain_or_retire_by_editorial_review'],
        'post' => ['classification' => 'EDITORIAL_DEFERRED', 'reason_code' => 'EDITORIAL_POST_REQUIRES_SEPARATE_REVIEW', 'target_boundary' => 'wp_posts', 'identity_rule' => 'native_post_identity', 'relation_rule' => 'governed_post_semantic_links_only', 'migration_action' => 'separate_editorial_review', 'retirement_rule' => 'retain_or_retire_by_editorial_review'],
        'page' => ['classification' => 'EDITORIAL_DEFERRED', 'reason_code' => 'EDITORIAL_POST_REQUIRES_SEPARATE_REVIEW', 'target_boundary' => 'wp_posts', 'identity_rule' => 'native_post_identity', 'relation_rule' => 'governed_post_semantic_links_only', 'migration_action' => 'separate_editorial_review', 'retirement_rule' => 'retain_or_retire_by_editorial_review'],
    ];

    /** @param list<array<string,mixed>> $records */
    public function run(array $records): array
    {
        $counts = [];
        $items = [];
        foreach ($records as $record) {
            $legacyType = (string) ($record['legacy_type'] ?? '');
            $definition = self::CLASSIFICATIONS[$legacyType] ?? ['classification' => 'REQUIRES_REVIEW', 'reason_code' => 'UNSUPPORTED_LEGACY_RECORD_REQUIRES_REVIEW', 'target_boundary' => 'unknown', 'identity_rule' => 'manual_identity_review', 'relation_rule' => 'no_relation_until_review', 'migration_action' => 'manual_review', 'retirement_rule' => 'retain_until_review'];
            $classification = $definition['classification'];
            $counts[$classification] = ($counts[$classification] ?? 0) + 1;
            $items[] = [
                'legacy_id' => (string) ($record['legacy_id'] ?? ''),
                'legacy_type' => $legacyType,
                'post_name' => (string) ($record['post_name'] ?? ''),
                'post_title' => (string) ($record['post_title'] ?? ''),
                'classification' => $classification,
                'reason_code' => $definition['reason_code'],
                'editorial_import_forbidden' => $classification !== 'EDITORIAL_DEFERRED',
                'mapping_applied' => false,
                'mapping_policy' => [
                    'target_boundary' => $definition['target_boundary'],
                    'identity_rule' => $definition['identity_rule'],
                    'relation_rule' => $definition['relation_rule'],
                    'migration_action' => $definition['migration_action'],
                    'retirement_rule' => $definition['retirement_rule'],
                ],
            ];
        }
        ksort($counts);
        return ['source_count' => count($records), 'counts' => $counts, 'items' => $items];
    }
}
