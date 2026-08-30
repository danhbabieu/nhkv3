<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use NHK\Core\Contracts\Graph\EndpointResolver;
use NHK\Core\Graph\Exception\UnsupportedEndpointType;
final class EndpointTypeRegistry {
    /** @var array<string,EndpointResolver> */ private array $resolvers = [];
    public function register(string $type, EndpointResolver $resolver): void { $this->resolvers[$type] = $resolver; }
    public function resolver(string $type): EndpointResolver { if (!isset($this->resolvers[$type])) throw new UnsupportedEndpointType('Unsupported endpoint type: '.$type); return $this->resolvers[$type]; }
    public function normalize(NodeReference $reference): NodeReference { return $this->resolver($reference->endpoint_type)->normalize($reference); }
    public function assertExists(NodeReference $reference): NodeReference { $normalized=$this->normalize($reference); if (!$this->resolver($normalized->endpoint_type)->exists($normalized)) throw new \NHK\Core\Graph\Exception\EndpointNotFound('Endpoint does not exist: '.$normalized->key()); return $normalized; }
}
