<?php
declare(strict_types=1);

namespace NHK\Core\Application\Semantic;

/** Shared surface policy: budgets constrain context, never canonical truth. */
final class EditorialCoveragePolicy
{
    /** @return array<string,mixed> */
    public function for(string $profile, string $topic, array $inputContext = []): array
    {
        return match (strtolower(trim($profile))) {
            'article' => ['aspect_target' => 3, 'token_budget' => 900, 'minimum_gain' => 0.25, 'max_expansion_rounds' => 2],
            'video' => ['aspect_target' => 2, 'token_budget' => 360, 'minimum_gain' => 0.35, 'max_expansion_rounds' => 1],
            'image', 'media' => ['aspect_target' => 2, 'token_budget' => 300, 'minimum_gain' => 0.35, 'max_expansion_rounds' => 1],
            default => ['aspect_target' => 1, 'token_budget' => 240, 'minimum_gain' => 0.5, 'max_expansion_rounds' => 0],
        };
    }
}
