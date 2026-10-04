<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Dictionary\DictionaryMentionPublicProjection;
use NHK\Core\Domain\Dictionary\DictionaryMention;
use PHPUnit\Framework\TestCase;

final class DictionaryMentionPublicProjectionTest extends TestCase
{
    public function test_public_article_mention_is_included_but_non_public_article_is_omitted(): void
    {
        $projection = $this->projection([
            $this->mention('m-article-public', 'ARTICLE', 'article-public'),
            $this->mention('m-article-private', 'ARTICLE', 'article-private'),
        ], static function (string $kind, string $id): ?array {
            return $id === 'article-public' ? ['id' => $id, 'title' => 'Bài công khai', 'url' => '/bai-viet/cong-khai/'] : null;
        });

        $result = $projection->forConcept('sense-1');

        self::assertSame([['id' => 'article-public', 'title' => 'Bài công khai', 'url' => '/bai-viet/cong-khai/', 'source_kind' => 'ARTICLE']], $result['groups']['ARTICLE']);
    }

    public function test_knowledge_mention_keeps_only_reader_safe_public_presentation(): void
    {
        $projection = $this->projection([
            $this->mention('m-knowledge-public', 'KNOWLEDGE', 'knowledge-public'),
            $this->mention('m-knowledge-private', 'KNOWLEDGE', 'knowledge-private'),
        ], static function (string $kind, string $id): ?array {
            return $id === 'knowledge-public' ? ['id' => $id, 'title' => 'Thông tin đã duyệt', 'url' => '/tri-thuc/thong-tin/'] : null;
        });

        $result = $projection->forConcept('sense-1');

        self::assertSame(['knowledge-public'], array_column($result['groups']['KNOWLEDGE'], 'id'));
        self::assertSame('/tri-thuc/thong-tin/', $result['groups']['KNOWLEDGE'][0]['url']);
    }

    public function test_media_mention_requires_an_approved_reader_destination_and_never_uses_placeholder_copy(): void
    {
        $projection = $this->projection([
            $this->mention('m-media-public', 'MEDIA', 'media-public'),
            $this->mention('m-media-private', 'MEDIA', 'media-private'),
        ], static function (string $kind, string $id): ?array {
            return $id === 'media-public' ? ['id' => $id, 'title' => 'Mặt số', 'url' => '/anh/mat-so.webp'] : null;
        });

        $result = $projection->forConcept('sense-1');

        self::assertSame(['media-public'], array_column($result['groups']['MEDIA'], 'id'));
        self::assertNotContains('Hình ảnh liên quan', array_column($result['groups']['MEDIA'], 'title'));
    }

    public function test_video_mention_requires_a_public_canonical_route_and_preserves_title_and_url(): void
    {
        $projection = $this->projection([
            $this->mention('m-video-public', 'VIDEO', 'video-public'),
            $this->mention('m-video-private', 'VIDEO', 'video-private'),
        ], static function (string $kind, string $id): ?array {
            return $id === 'video-public' ? ['id' => $id, 'title' => 'Video kỹ thuật', 'url' => '/video/ky-thuat/'] : null;
        });

        $result = $projection->forConcept('sense-1');

        self::assertSame([['id' => 'video-public', 'title' => 'Video kỹ thuật', 'url' => '/video/ky-thuat/', 'source_kind' => 'VIDEO']], $result['groups']['VIDEO']);
    }

    /** @param list<DictionaryMention> $mentions */
    private function projection(array $mentions, callable $resolver): DictionaryMentionPublicProjection
    {
        return new DictionaryMentionPublicProjection(new class($mentions) {
            public function __construct(private array $mentions) {}
            public function listByConcept(string $conceptId, int $limit, int $offset): array { return $this->mentions; }
        }, $resolver);
    }

    private function mention(string $id, string $kind, string $sourceId): DictionaryMention
    {
        return new DictionaryMention($id, str_repeat('a', 64), $kind, $sourceId, 'thuật ngữ', 'context', strength: 'STRONG');
    }
}
