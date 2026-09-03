<?php
declare(strict_types=1);

namespace NHK\Core\Contracts\Article;

interface OwnerPublicationService
{
    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function request(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey, PublicationPrincipal $principal): array;
    /** @param array<string,mixed> $evidence @return array<string,mixed> */
    public function approveAndPublish(int $postId, string $expectedStateToken, array $evidence, string $idempotencyKey, string $decisionId, PublicationPrincipal $principal, string $affirmation): array;
}
