<?php
declare(strict_types=1);
namespace NHK\Core\Infrastructure\Graph;
use NHK\Core\Contracts\Graph\EndpointResolver;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Graph\Exception\InvalidEndpointReference;
final class WpPostEndpointResolver implements EndpointResolver {
    public function supports(string $endpoint_type): bool { return $endpoint_type === 'wp_post'; }
    public function normalize(NodeReference $reference): NodeReference {
        if (!$this->supports($reference->endpoint_type) || preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $reference->endpoint_key) !== 1) throw new InvalidEndpointReference('wp_post key must be <blog_id>:<post_id>.');
        return new NodeReference('wp_post', $reference->endpoint_key);
    }
    public function exists(NodeReference $reference): bool { [$blogId,$postId]=array_map('intval',explode(':',$reference->endpoint_key,2)); return $blogId === (int)get_current_blog_id() && get_post($postId) instanceof \WP_Post; }
}
