<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleVerificationReader;
use NHK\Core\Domain\Article\EditorialPostState;
use PHPUnit\Framework\TestCase;

final class ArticleVerificationReaderTest extends TestCase
{
    public function test_unchanged_editorial_state_and_applied_proposals_verify_successfully(): void
    {
        $state = new EditorialPostState(55, '1:55', 'post', 'publish', 'Title', 'Body', '', 'slug', 'https://example.test/slug/', 0, 0);
        $result = (new ArticleVerificationReader())->verify($state, $state, ['p1'], ['p1']);

        self::assertTrue($result->verified);
        self::assertSame([], $result->reasons);
    }

    public function test_changed_editorial_state_fails_verification(): void
    {
        $initial = new EditorialPostState(55, '1:55', 'post', 'publish', 'Title', 'Body', '', 'slug', 'https://example.test/slug/', 0, 0);
        $current = new EditorialPostState(55, '1:55', 'post', 'publish', 'Title', 'Changed', '', 'slug', 'https://example.test/slug/', 0, 0);
        $result = (new ArticleVerificationReader())->verify($initial, $current, [], []);

        self::assertFalse($result->verified);
        self::assertContains('EDITORIAL_STATE_CHANGED', $result->reasons);
    }
}
