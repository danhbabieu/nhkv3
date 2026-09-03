<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Article;

use NHK\Core\Domain\Article\OwnerPublicationDecision;

interface OwnerPublicationDecisionRepository
{
    public function findByIdempotencyKey(string $key): ?OwnerPublicationDecision;
    public function findActiveApproval(int $postId, string $token, string $policyVersion, string $blockerFingerprint, string $principalId): ?OwnerPublicationDecision;
    public function create(OwnerPublicationDecision $decision): OwnerPublicationDecision;
    public function append(OwnerPublicationDecision $decision): OwnerPublicationDecision;
}
