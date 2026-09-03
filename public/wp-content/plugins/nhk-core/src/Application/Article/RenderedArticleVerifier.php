<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Domain\Article\RenderedArticleVerificationResult;

/** Verifies the public HTML projection; stored DTO evidence alone is never enough. */
final class RenderedArticleVerifier
{
    /** @param array<string,mixed> $stored @param array<string,mixed> $expectations */
    public function verify(?string $html, ?string $route, array $stored = [], array $expectations = []): RenderedArticleVerificationResult
    {
        if ($html === null || trim($html) === '' || $route === null || trim($route) === '') {
            return new RenderedArticleVerificationResult('unavailable_runtime', false, [], ['RENDERED_RUNTIME_UNAVAILABLE'], $route);
        }
        $text = strtolower(strip_tags($html));
        $checks = [
            'title' => $this->hasTag($html, 'title'),
            'h1' => $this->hasTag($html, 'h1'),
            'slug_permalink' => $this->hasAttributeValue($html, 'link', 'rel', 'canonical') || $this->hasUrl($html, $route),
            'canonical' => $this->hasAttributeValue($html, 'link', 'rel', 'canonical'),
            'meta_description' => $this->hasMeta($html, 'description'),
            'indexability_robots' => $this->hasMeta($html, 'robots') || !str_contains($text, 'noindex'),
            'category' => $this->expectedOrText($expectations, 'category', $text),
            'internal_links' => $this->hasInternalLink($html),
            'featured_image' => $this->hasImage($html, 'featured'),
            'inline_image' => $this->hasImage($html, 'inline'),
            'alt_caption_context' => $this->hasContextualImageText($html),
            'related_content' => $this->expectedOrText($expectations, 'related_content', $text),
            'structured_data' => str_contains(strtolower($html), 'application/ld+json'),
            'public_claim_compliance' => ($stored['claim_compliance_acceptable'] ?? $expectations['public_claim_compliance'] ?? false) === true,
            'semantic_readiness' => ($stored['semantic_ready'] ?? $expectations['semantic_readiness'] ?? false) === true,
            'media_completeness' => ($stored['media_complete'] ?? $expectations['media_completeness'] ?? false) === true,
        ];
        $reasons = [];
        foreach ($checks as $name => $pass) if (!$pass) $reasons[] = 'RENDERED_' . strtoupper($name) . '_FAILED';
        return new RenderedArticleVerificationResult('rendered_public_route', $reasons === [], $checks, $reasons, $route);
    }

    private function hasTag(string $html, string $tag): bool { return preg_match('/<' . preg_quote($tag, '/') . '\\b[^>]*>/i', $html) === 1; }
    private function hasMeta(string $html, string $name): bool { return preg_match('/<meta\\b[^>]*(?:name|property)=["\\\']' . preg_quote($name, '/') . '["\\\'][^>]*>/i', $html) === 1; }
    private function hasAttributeValue(string $html, string $tag, string $attribute, string $value): bool { return preg_match('/<' . preg_quote($tag, '/') . '\\b[^>]*' . preg_quote($attribute, '/') . '=["\\\']' . preg_quote($value, '/') . '["\\\'][^>]*>/i', $html) === 1; }
    private function hasUrl(string $html, string $url): bool { return str_contains($html, htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) || str_contains($html, $url); }
    private function hasInternalLink(string $html): bool { return preg_match('/<a\\b[^>]*href=["\\\'](?:\\/|[^"\\\']*localhost)[^"\\\']*["\\\'][^>]*>/i', $html) === 1; }
    private function hasImage(string $html, string $role): bool { return preg_match('/<img\\b[^>]*(?:data-role|class)=["\\\'][^"\\\']*' . preg_quote($role, '/') . '[^"\\\']*["\\\'][^>]*>/i', $html) === 1; }
    private function hasContextualImageText(string $html): bool { return preg_match('/<img\\b[^>]*alt=["\\\'][^"\\\']+[^"\\\']*["\\\'][^>]*>/i', $html) === 1 && (str_contains(strtolower($html), '<figcaption') || preg_match('/<img\\b[^>]*alt=["\\\'][^"\\\']{8,}["\\\'][^>]*>/i', $html) === 1); }
    private function expectedOrText(array $expectations, string $key, string $text): bool { return array_key_exists($key, $expectations) ? $expectations[$key] === true : str_contains($text, $key === 'related_content' ? 'liên quan' : 'category'); }
}
