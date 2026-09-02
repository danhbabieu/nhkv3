<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Article\EditorialStateToken;
use PHPUnit\Framework\TestCase;

final class EditorialStateTokenTest extends TestCase
{
    public function test_token_is_deterministic_for_same_canonical_state(): void
    {
        $left = ['post_id' => 55, 'title' => 'Post', 'content' => 'Body', 'excerpt' => '', 'status' => 'publish', 'slug' => 'post', 'permalink' => 'https://example.test/post/', 'latest_revision_id' => 8, 'revision_count' => 2];
        $right = ['revision_count' => 2, 'latest_revision_id' => 8, 'permalink' => 'https://example.test/post/', 'slug' => 'post', 'status' => 'publish', 'excerpt' => '', 'content' => 'Body', 'title' => 'Post', 'post_id' => 55];

        self::assertSame(EditorialStateToken::fromState($left), EditorialStateToken::fromState($right));
    }

    public function test_token_changes_when_any_preserved_editorial_value_changes(): void
    {
        $state = ['post_id' => 55, 'title' => 'Post', 'content' => 'Body', 'excerpt' => '', 'status' => 'publish', 'slug' => 'post', 'permalink' => 'https://example.test/post/', 'latest_revision_id' => 8, 'revision_count' => 2];
        $changed = $state;
        $changed['content'] = 'Changed';

        self::assertNotSame(EditorialStateToken::fromState($state), EditorialStateToken::fromState($changed));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', EditorialStateToken::fromState($state));
    }
}
