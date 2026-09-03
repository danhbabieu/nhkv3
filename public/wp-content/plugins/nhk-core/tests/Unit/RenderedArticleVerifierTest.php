<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\RenderedArticleVerifier;
use PHPUnit\Framework\TestCase;

final class RenderedArticleVerifierTest extends TestCase
{
    public function test_unavailable_runtime_is_not_an_empty_or_successful_result(): void
    {
        $result = (new RenderedArticleVerifier())->verify(null, '/bai-viet/x');
        self::assertFalse($result->verified);
        self::assertSame('unavailable_runtime', $result->runtime);
        self::assertContains('RENDERED_RUNTIME_UNAVAILABLE', $result->reasons);
    }

    public function test_stored_state_does_not_pass_without_public_rendered_evidence(): void
    {
        $html = '<html><head><title>Bài viết</title><link rel="canonical" href="/bai-viet/x"><meta name="description" content="Mô tả"><meta name="robots" content="index"></head><body><h1>Bài viết</h1><a href="/bai-viet/y">Liên quan</a><figure><img data-role="featured" alt="Ảnh đồng hồ cổ điển"><figcaption>Ảnh minh họa</figcaption></figure><img data-role="inline" alt="Chi tiết mặt số"><script type="application/ld+json">{}</script></body></html>';
        $result = (new RenderedArticleVerifier())->verify($html, '/bai-viet/x', [
            'claim_compliance_acceptable' => true,
            'semantic_ready' => true,
            'media_complete' => true,
        ], ['category' => true, 'related_content' => true]);
        self::assertTrue($result->verified);
        self::assertTrue($result->checks['canonical']);
        self::assertTrue($result->checks['structured_data']);
    }

    public function test_rendered_failure_is_field_specific(): void
    {
        $result = (new RenderedArticleVerifier())->verify('<title>Draft</title><h1>Draft</h1>', '/bai-viet/x', ['semantic_ready' => true]);
        self::assertFalse($result->verified);
        self::assertContains('RENDERED_CANONICAL_FAILED', $result->reasons);
        self::assertContains('RENDERED_MEDIA_COMPLETENESS_FAILED', $result->reasons);
    }
}
