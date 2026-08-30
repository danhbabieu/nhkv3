<?php
declare(strict_types=1);
namespace NHK\Core\Domain\Graph;
use NHK\Core\Contracts\Graph\EndpointResolver;
final class FakeEndpointResolver implements EndpointResolver {
    public function __construct(private string $type, private array $keys = []) {}
    public function supports(string $endpoint_type): bool { return $endpoint_type === $this->type; }
    public function exists(NodeReference $reference): bool { return in_array($reference->endpoint_key,$this->keys,true); }
    public function normalize(NodeReference $reference): NodeReference { return new NodeReference($this->type,strtolower(trim($reference->endpoint_key))); }
}
