<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Domain\Article\EditorialPostState;
use PHPUnit\Framework\TestCase;

final class EditorialPostStateTest extends TestCase
{
    public function test_snapshot_contains_preserved_editorial_fields_and_token(): void
    {
        $state = new EditorialPostState(55, '1:55', 'post', 'publish', 'Title', 'Body', 'Excerpt', 'slug', 'https://example.test/slug/', 7, 3);
        $snapshot = $state->snapshot();

        self::assertSame(55, $snapshot['post_id']);
        self::assertSame('Body', $snapshot['content']);
        self::assertSame(64, strlen($state->token));
    }
}
