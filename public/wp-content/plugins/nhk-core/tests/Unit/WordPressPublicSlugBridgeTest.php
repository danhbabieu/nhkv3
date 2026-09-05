<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Infrastructure\PublicIdentity\WordPressPublicSlugBridge;
use PHPUnit\Framework\TestCase;

final class WordPressPublicSlugBridgeTest extends TestCase
{
    public function test_save_context_uses_shared_public_slug_policy(): void
    {
        $bridge = new WordPressPublicSlugBridge();

        self::assertSame('tuoi-tho-nha-kho', $bridge->sanitize('legacy-result', 'Tuổi thọ NHK', 'save'));
        self::assertSame('nguoi-suu-tap', $bridge->sanitize('legacy-result', 'Người sưu tập', 'save'));
    }

    public function test_non_save_context_is_not_rewritten(): void
    {
        $bridge = new WordPressPublicSlugBridge();

        self::assertSame('wordpress-query-value', $bridge->sanitize('wordpress-query-value', 'Tuổi thọ NHK', 'query'));
    }

    public function test_empty_policy_result_falls_back_to_wordpress_value(): void
    {
        $bridge = new WordPressPublicSlugBridge();

        self::assertSame('wordpress-fallback', $bridge->sanitize('wordpress-fallback', '---', 'save'));
    }
}
