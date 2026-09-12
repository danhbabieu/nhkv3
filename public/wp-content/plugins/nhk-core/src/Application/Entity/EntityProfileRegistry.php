<?php
declare(strict_types=1);

namespace NHK\Core\Application\Entity;

/**
 * Application-level registry for profile/read behavior. Predicate legality,
 * Public Identity ownership and domain persistence remain elsewhere.
 */
final class EntityProfileRegistry
{
    /** @var array<string,EntityProfileDefinition> */
    private array $definitions;

    public function __construct()
    {
        $this->definitions = [
            'brand' => new EntityProfileDefinition(
                'brand',
                'Thương hiệu',
                'Brand',
                ['entity_type' => 'brand'],
                ['identity', 'public_identity', 'knowledge', 'media', 'video', 'article_relations', 'related_entities', 'representative_media', 'specimen_context', 'product_context', 'seo'],
                'brand-entity-hub-v1',
                ['models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products', 'media', 'videos', 'articles'],
                [],
                ['archive_path' => '/thuong-hieu/', 'navigation_label' => 'Thương hiệu'],
                ['kind' => 'root_public_identity', 'enabled' => false, 'route_owner' => 'public_identity', 'resolution' => 'read_foundation', 'allocation' => 'governed_only'],
                ['dossier_read', 'related_read', 'knowledge_read', 'media_read', 'video_read', 'seo_read'],
                ['section_order' => ['identity', 'knowledge', 'models', 'variants', 'media', 'video', 'articles'], 'section_labels' => ['knowledge' => 'Tri thức'], 'empty_state' => 'Chưa có nội dung công khai phù hợp.'],
            ),
            'clock_type' => new EntityProfileDefinition(
                'clock_type',
                'Loại đồng hồ',
                'Clock Type',
                ['entity_type' => 'classification', 'family' => 'clock_type'],
                ['identity', 'public_identity', 'knowledge', 'media', 'video', 'article_relations', 'related_entities', 'representative_media', 'specimen_context', 'product_context', 'brand_context', 'subtype_context', 'seo'],
                'clock-type-entity-hub-v1',
                ['knowledge', 'media', 'video', 'models', 'variants', 'specimens', 'products', 'brands', 'classifications'],
                ['clock-type'],
                ['archive_path' => '/loai-dong-ho/', 'navigation_label' => 'Loại đồng hồ'],
                ['kind' => 'root_public_identity', 'enabled' => false, 'route_owner' => 'public_identity', 'resolution' => 'read_foundation', 'allocation' => 'governed_only'],
                ['dossier_read', 'related_read', 'knowledge_read', 'media_read', 'video_read', 'specimen_read', 'product_read', 'brand_derived_read', 'subtype_read', 'seo_read'],
                ['section_order' => ['identity', 'knowledge', 'classifications', 'brands', 'models', 'variants', 'specimens', 'products', 'media', 'video', 'articles'], 'section_labels' => ['knowledge' => 'Tri thức', 'brands' => 'Thương hiệu liên quan'], 'empty_state' => 'Chưa có nội dung công khai cho loại đồng hồ này.'],
            ),
        ];
    }

    /** @return list<string> */
    public function keys(): array { return array_keys($this->definitions); }

    /** @return list<EntityProfileDefinition> */
    public function all(): array { return array_values($this->definitions); }

    public function get(string $key): ?EntityProfileDefinition { return $this->definitions[$key] ?? null; }
}
