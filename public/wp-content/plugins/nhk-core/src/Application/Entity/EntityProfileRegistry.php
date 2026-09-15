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
                'Nhóm đồng hồ',
                'Clock Type',
                ['entity_type' => 'classification', 'family' => 'clock_type'],
                ['identity', 'public_identity', 'knowledge', 'media', 'video', 'article_relations', 'related_entities', 'representative_media', 'specimen_context', 'product_context', 'brand_context', 'subtype_context', 'seo'],
                'clock-type-entity-hub-v1',
                ['knowledge', 'media', 'video', 'models', 'variants', 'specimens', 'products', 'brands', 'classifications'],
                ['clock-type'],
                ['archive_path' => '/loai-dong-ho/', 'navigation_label' => 'Nhóm đồng hồ'],
                ['kind' => 'root_public_identity', 'enabled' => false, 'route_owner' => 'public_identity', 'resolution' => 'read_foundation', 'allocation' => 'governed_only', 'route_prefix' => 'dong-ho-', 'strip_lexical_prefix' => 'Đồng hồ '],
                ['dossier_read', 'related_read', 'knowledge_read', 'media_read', 'video_read', 'specimen_read', 'product_read', 'brand_derived_read', 'subtype_read', 'seo_read'],
                ['section_order' => ['identity', 'knowledge', 'classifications', 'brands', 'models', 'variants', 'specimens', 'products', 'media', 'video', 'articles'], 'section_labels' => ['knowledge' => 'Tri thức', 'brands' => 'Thương hiệu liên quan'], 'empty_state' => 'Chưa có nội dung công khai cho nhóm đồng hồ này.'],
            ),
        ];

        foreach ([
            'model' => ['Mẫu đồng hồ', '/mau/', ['variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'products', 'media', 'videos', 'articles'], ['identity', 'parent_context', 'summary', 'variants', 'movements', 'music', 'knowledge', 'media', 'videos', 'articles']],
            'variant' => ['Biến thể', '/bien-the/', ['models', 'movements', 'music', 'components', 'classifications', 'specimens', 'media', 'videos', 'articles'], ['identity', 'parent_context', 'summary', 'configuration', 'movement', 'music', 'knowledge', 'media', 'videos', 'articles']],
            'movement' => ['Bộ máy', '/bo-may/', ['brands', 'models', 'variants', 'music', 'components', 'specimens', 'media', 'videos', 'articles'], ['identity', 'summary', 'technical_configuration', 'models', 'variants', 'music', 'knowledge', 'media', 'videos', 'articles']],
            'music' => ['Bản nhạc', '/ban-nhac/', ['brands', 'models', 'variants', 'movements', 'specimens', 'media', 'videos', 'articles'], ['identity', 'summary', 'historical_context', 'movements', 'models', 'variants', 'brands', 'knowledge', 'media', 'videos', 'articles']],
            'component' => ['Linh kiện', '/linh-kien/', ['brands', 'models', 'variants', 'movements', 'classifications', 'media', 'videos', 'articles'], ['identity', 'summary', 'technical_configuration', 'movements', 'models', 'variants', 'knowledge', 'media', 'videos', 'articles']],
            'classification' => ['Phân loại', '/phan-loai/', ['brands', 'models', 'variants', 'movements', 'classifications', 'specimens', 'products', 'media', 'videos', 'articles'], ['identity', 'summary', 'related_entities', 'knowledge', 'media', 'videos', 'articles']],
            'specimen' => ['Hiện vật', '/hien-vat/', ['brands', 'models', 'variants', 'movements', 'music', 'components', 'classifications', 'products', 'media', 'videos', 'articles'], ['identity', 'parent_context', 'measurements', 'provenance', 'knowledge', 'articles', 'media', 'videos', 'related_entities']],
            'product' => ['Sản phẩm', '/san-pham/', ['brands', 'models', 'variants', 'movements', 'music', 'components', 'classifications', 'specimens', 'media', 'videos', 'articles'], ['identity', 'object_context', 'brand_context', 'model_context', 'variant_context', 'media', 'videos', 'articles', 'knowledge']],
        ] as $key => [$label, $archivePath, $targets, $sectionOrder]) {
            $this->definitions[$key] = $this->generic($key, $label, $archivePath, $targets, $sectionOrder);
        }
    }

    /** @return list<string> */
    public function keys(): array { return array_keys($this->definitions); }

    /** @return list<EntityProfileDefinition> */
    public function all(): array { return array_values($this->definitions); }

    public function get(string $key): ?EntityProfileDefinition { return $this->definitions[$key] ?? null; }

    /** @param list<string> $targets @param list<string> $sectionOrder */
    private function generic(string $key, string $label, string $archivePath, array $targets, array $sectionOrder): EntityProfileDefinition
    {
        return new EntityProfileDefinition(
            $key,
            $label,
            $key,
            ['entity_type' => $key],
            array_values(array_unique(['identity', 'public_identity', 'knowledge', 'media', 'video', 'article_relations', 'related_entities', 'representative_media', 'seo', ...$sectionOrder])),
            $key . '-entity-dossier-v1',
            $targets,
            [],
            ['archive_path' => $archivePath, 'navigation_label' => $label],
            ['kind' => 'root_public_identity', 'enabled' => false, 'route_owner' => 'public_identity', 'resolution' => 'read_foundation', 'allocation' => 'governed_only'],
            ['dossier_read', 'related_read', 'knowledge_read', 'media_read', 'video_read', 'seo_read'],
            ['section_order' => $sectionOrder, 'preview_limits' => ['articles' => 5, 'media' => 6, 'videos' => 4, 'knowledge' => 5]],
        );
    }
}
