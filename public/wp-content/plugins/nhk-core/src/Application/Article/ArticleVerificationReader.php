<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Domain\Article\{ArticleVerificationResult, EditorialPostState};

final class ArticleVerificationReader
{
    /** @param list<string> $proposalIds @param list<string> $appliedProposalIds */
    public function verify(EditorialPostState $initial, EditorialPostState $current, array $proposalIds, array $appliedProposalIds): ArticleVerificationResult
    {
        $reasons = [];
        if ($initial->token !== $current->token) $reasons[] = 'EDITORIAL_STATE_CHANGED';
        if ($initial->postId !== $current->postId || $initial->endpointKey !== $current->endpointKey) $reasons[] = 'WP_POST_IDENTITY_CHANGED';
        foreach ($proposalIds as $proposalId) if (!in_array($proposalId, $appliedProposalIds, true)) $reasons[] = 'SEMANTIC_PROPOSAL_NOT_APPLIED';
        return new ArticleVerificationResult($reasons === [], array_values(array_unique($reasons)));
    }
}
