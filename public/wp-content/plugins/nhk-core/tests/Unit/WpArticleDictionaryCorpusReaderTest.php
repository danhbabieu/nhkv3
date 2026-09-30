<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Article\EditorialPostState;
use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Infrastructure\Article\WpArticleDictionaryCorpusReader;
use PHPUnit\Framework\TestCase;

final class WpArticleDictionaryCorpusReaderTest extends TestCase
{
    public function test_reader_scans_only_canonical_publish_and_draft_articles_and_keeps_empty_eligible_source(): void
    {
        $states = new class implements EditorialStateReader {
            public function read(int $postId): ?EditorialPostState
            {
                return new EditorialPostState($postId, '1:' . $postId, 'post', $postId === 3 ? 'private' : ($postId === 4 ? 'publish' : 'draft'), $postId === 4 ? 'Hello World' : 'Article ' . $postId, $postId === 2 ? '' : 'Body ' . $postId, '', 'article-' . $postId, '/article-' . $postId, 0, 0);
            }
        };
        $db = new class {
            public string $posts = 'wp_posts';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_results(string $sql, mixed $output): array
            {
                return [['ID' => 1, 'post_status' => 'draft'], ['ID' => 2, 'post_status' => 'draft'], ['ID' => 3, 'post_status' => 'private'], ['ID' => 4, 'post_status' => 'publish']];
            }
        };

        $page = (new WpArticleDictionaryCorpusReader($db, $states))->page(null, 10);

        self::assertSame(['1', '2'], array_column($page['items'], 'source_id'));
        self::assertCount(2, $page['items']);
        self::assertFalse($page['has_more']);
    }

    public function test_large_or_bad_source_is_reported_without_dropping_the_page(): void
    {
        $states = new class implements EditorialStateReader {
            public function read(int $postId): ?EditorialPostState
            {
                if ($postId === 2) throw new \RuntimeException('large source read failed');
                return new EditorialPostState($postId, '1:' . $postId, 'post', 'publish', 'Article ' . $postId, str_repeat('x', $postId === 1 ? 10000 : 10), '', 'article-' . $postId, '/article-' . $postId, 0, 0);
            }
        };
        $db = new class {
            public string $posts = 'wp_posts';
            public function prepare(string $sql, mixed ...$args): string { return $sql; }
            public function get_results(string $sql, mixed $output): array { return [['ID' => 1], ['ID' => 2], ['ID' => 3]]; }
        };

        $page = (new WpArticleDictionaryCorpusReader($db, $states))->page(null, 10);

        self::assertSame(['1', '2', '3'], array_column($page['items'], 'source_id'));
        self::assertSame('ARTICLE_SOURCE_READ_FAILED', $page['diagnostics'][0]['code']);
        self::assertSame('2', $page['diagnostics'][0]['source_id']);
    }
}
