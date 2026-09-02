<?php
declare(strict_types=1);

namespace NHK\Core\Application\Knowledge;

use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Domain\Governance\{CommandCanonicalizer, Proposal, ProposalState};
use NHK\Core\Governance\Exception\GovernanceException;
use NHK\Core\Shared\Uuid\UuidCodec;

final class PostKnowledgeLinkService
{
    public function __construct(private GovernanceService $governance) {}

    /** Request a governed relation proposal; Controlled Apply remains the only semantic write path. */
    public function request(string $blogId, int $postId, string $claimId, string $idempotencyKey, int $expectedRevision = 1): Proposal
    {
        if ($postId < 1 || !UuidCodec::isValid($claimId) || trim($idempotencyKey) === '') {
            throw new \InvalidArgumentException('Post knowledge relation proposal is invalid.');
        }
        $payload = [
            'source_type' => 'wp_post',
            'source_key' => trim($blogId) . ':' . $postId,
            'predicate' => 'about',
            'target_type' => 'knowledge',
            'target_key' => $claimId,
        ];
        $content = hash('sha256', CommandCanonicalizer::canonicalize($payload));
        $dependencies = hash('sha256', CommandCanonicalizer::canonicalize([]));
        return $this->governance->create(new Proposal(
            UuidCodec::newV7(),
            'relation',
            'relation_create',
            $payload,
            $content,
            $expectedRevision,
            $dependencies,
            ProposalState::DRAFT,
            idempotencyKey: $idempotencyKey,
            entityType: 'relation',
        ));
    }

    /** @deprecated Direct Post-to-Knowledge semantic writes are constitutionally forbidden. */
    public function link(string $blogId, int $postId, string $claimId): never
    {
        throw new GovernanceException('Direct Post-to-Knowledge semantic mutation is unavailable; request a governed proposal.');
    }
}
