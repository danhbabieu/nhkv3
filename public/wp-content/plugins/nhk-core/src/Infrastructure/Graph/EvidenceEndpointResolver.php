<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Graph;

use NHK\Core\Contracts\Graph\EndpointResolver;
use NHK\Core\Contracts\Knowledge\EvidenceRepository;
use NHK\Core\Domain\Graph\NodeReference;
use NHK\Core\Graph\Exception\InvalidEndpointReference;

final class EvidenceEndpointResolver implements EndpointResolver
{
    public function __construct(private EvidenceRepository $repository) {}
    public function supports(string $endpoint_type): bool { return $endpoint_type === 'evidence'; }
    public function normalize(NodeReference $reference): NodeReference { if (!$this->supports($reference->endpoint_type) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $reference->endpoint_key) !== 1) throw new InvalidEndpointReference('Evidence endpoint key must be UUID.'); return $reference; }
    public function exists(NodeReference $reference): bool { return $this->repository->findByCanonicalId($reference->endpoint_key) !== null; }
}
