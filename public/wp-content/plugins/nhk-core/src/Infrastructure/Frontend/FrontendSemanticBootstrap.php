<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Frontend;

use NHK\Core\Application\Entity\EntityMediaProjection;
use NHK\Core\Application\Knowledge\{EntityKnowledgeProjection, KnowledgePageQuery};
use NHK\Core\Application\Media\{PublicMediaAssetDelivery, PublicMediaGalleryQuery};
use NHK\Core\Infrastructure\Knowledge\{WpdbEvidenceRepository, WpdbKnowledgeRepository, WpdbSourceRepository};
use NHK\Core\Infrastructure\Media\{WpdbMediaAssetRepository, WpdbMediaRepository, WpdbMediaUsageRepository};
use NHK\Core\Shared\Migration\MigrationStatus;

/**
 * Adds presentation-only projections without becoming a semantic writer.
 * All data still comes from the canonical repositories and governed public gates.
 */
final class FrontendSemanticBootstrap
{
    public static function boot(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !function_exists('add_filter')) return;

        $status = new MigrationStatus();
        $media = new WpdbMediaRepository($wpdb);
        $assets = new WpdbMediaAssetRepository($wpdb);
        $usages = new WpdbMediaUsageRepository($wpdb);
        $claims = new WpdbKnowledgeRepository($wpdb);
        $evidence = new WpdbEvidenceRepository($wpdb);
        $sources = new WpdbSourceRepository($wpdb);
        $gallery = new PublicMediaGalleryQuery($media, $assets, PublicMediaAssetDelivery::fromEnvironment($assets, $media));
        $entityMedia = new EntityMediaProjection($media, $assets, $usages);
        $entityKnowledge = new EntityKnowledgeProjection($claims, $evidence, $sources, $status);
        $knowledgeArchive = new KnowledgePageQuery($claims, $evidence, $sources, $status);

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

        add_filter('nhk_v3_entity_detail_projection', static function(array $item, object $entity) use ($entityKnowledge): array {
            if (property_exists($entity, 'canonicalId')) $item['knowledge'] = $entityKnowledge->forSubject((string) $entity->canonicalId);
            return $item;
        }, 10, 2);

        add_filter('nhk_v3_related_media_item', static function(array $item, object $mediaEntity) use ($gallery): array {
            if (!property_exists($mediaEntity, 'canonicalId')) return $item;
            $visual = $gallery->forMedia((string) $mediaEntity->canonicalId);
            if (!is_array($visual)) return $item;
            foreach (['image_url','alt','width','height','has_real_image'] as $key) if (array_key_exists($key, $visual)) $item[$key] = $visual[$key];
            return $item;
        }, 10, 2);

        add_filter('nhk_v3_article_media_gallery', static function(array $value, int $postId) use ($entityMedia): array {
            if ($postId < 1) return $value;
            $blogId = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;
            $projection = $entityMedia->forEntity('wp_post', $blogId . ':' . $postId);
            return is_array($projection) ? $projection : $value;
        }, 10, 2);
    }
}
