<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Graph;
use NHK\Core\Contracts\Graph\EndpointRevisionReader;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Graph\Exception\InvalidEndpointReference;
final class WpPostEndpointResolver implements EndpointRevisionReader {
    /** @param callable(int):object|null $postReader @param callable():int|null $blogReader */
    public function __construct(private $postReader = null, private $blogReader = null) {}
    public function supports(string $endpoint_type): bool { return $endpoint_type === 'wp_post'; }
    public function normalize(NodeReference $reference): NodeReference {
        if (!$this->supports($reference->endpoint_type) || preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $reference->endpoint_key) !== 1) throw new InvalidEndpointReference('wp_post key must be <blog_id>:<post_id>.');
        return new NodeReference('wp_post', $reference->endpoint_key);
    }
    public function exists(NodeReference $reference): bool { [$blogId,$postId]=array_map('intval',explode(':',$reference->endpoint_key,2)); return $blogId === $this->blogId() && $this->post($postId) !== null; }
    public function revision(NodeReference $reference): ?int
    {
        [, $postId] = array_map('intval', explode(':', $reference->endpoint_key, 2));
        $post = $this->post($postId);
        if ($post === null) return null;
        return $this->timestamp($post->post_modified_gmt ?? null, true)
            ?? $this->timestamp($post->post_modified ?? null, false);
    }
    public function state(NodeReference $reference): array { [, $postId] = array_map('intval', explode(':', $reference->endpoint_key, 2)); $post=$this->post($postId); return ['exists'=>$post!==null,'active'=>$post!==null && (string) ($post->post_status ?? '') !== 'trash','revision'=>$this->revision($reference),'family'=>null]; }

    private function timestamp(mixed $value, bool $isGmt): ?int
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || $value === '0000-00-00 00:00:00') return null;
        if (!$isGmt) {
            $value = function_exists('get_gmt_from_date') ? (string) get_gmt_from_date($value) : $value . ' UTC';
        } elseif (!preg_match('/(?:UTC|Z|[+-][0-9]{2}:?[0-9]{2})$/i', $value)) {
            $value .= ' UTC';
        }
        $timestamp = strtotime($value);
        return is_int($timestamp) && $timestamp > 0 ? $timestamp : null;
    }
    private function post(int $postId): ?object
    {
        $post = is_callable($this->postReader) ? ($this->postReader)($postId) : (function_exists('get_post') ? get_post($postId) : null);
        return is_object($post) ? $post : null;
    }
    private function blogId(): int
    {
        return is_callable($this->blogReader) ? (int) ($this->blogReader)() : (function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1);
    }
}
