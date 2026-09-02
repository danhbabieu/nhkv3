<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

use NHK\Core\Domain\Article\ArticleOperationReceipt;

final class ArticleDiagnosticReader
{
    /** @return array<string,mixed> */
    public function describe(ArticleOperationReceipt $receipt, array $context = []): array
    {
        $diagnostic = [
            'operation_id' => $receipt->operationId,
            'idempotency_key' => $receipt->idempotencyKey,
            'intent' => $receipt->intent,
            'wp_endpoint_key' => $receipt->wpEndpointKey,
            'wp_post_id' => $receipt->wpPostId,
            'wp_state_token' => $receipt->wpStateToken,
            'stage' => $receipt->stage,
            'outcome' => $receipt->outcome->value,
            'retryable' => $receipt->retryable,
            'proposal_ids' => $receipt->proposalIds,
            'applied_proposal_ids' => $receipt->appliedProposalIds,
            'last_failure' => $receipt->failure,
        ];
        foreach (['preflight', 'proposal_states', 'eligibility', 'apply_attempts', 'verification'] as $key) {
            if (array_key_exists($key, $context)) $diagnostic[$key] = $this->withoutEditorialBody($context[$key]);
        }
        return $diagnostic;
    }

    private function withoutEditorialBody(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        $safe = [];
        foreach ($value as $key => $item) {
            if (in_array((string) $key, ['body', 'content', 'post_content'], true)) continue;
            $safe[$key] = $this->withoutEditorialBody($item);
        }
        return $safe;
    }
}
