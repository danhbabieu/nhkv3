<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Article\ArticleIngestCoordinator;
use NHK\Core\Application\Article\ArticleIngestPreflight;
use NHK\Core\Contracts\Article\EditorialStateReader;

class McpArticleIngestHandler
{
    public function __construct(
        private ArticleIngestCoordinator $coordinator,
        private ArticleIngestPreflight $preflight,
        private EditorialStateReader $editorial,
    ) {}

    /** @return array<string,mixed> */
    public function preflight(array $input): array
    {
        $target = is_array($input['target_wp_post'] ?? null) ? $input['target_wp_post'] : [];
        $endpoint = trim((string) ($target['endpoint_key'] ?? ''));
        $intent = (string) ($input['intent'] ?? '');
        $commands = is_array($input['semantic_bundle']['commands'] ?? null) ? $input['semantic_bundle']['commands'] : [];
        $result = $this->preflight->check($endpoint, $intent, $commands, (string) ($target['endpoint_type'] ?? ''));
        $details = $result->details;
        if ($result->accepted && preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $endpoint, $matches) === 1) {
            $state = $this->editorial->read((int) $matches[1]);
            if ($state === null) {
                return ['accepted' => false, 'reasons' => ['WP_POST_UNAVAILABLE'], 'details' => $details];
            }
            $details['wp_post_id'] = $state->postId;
            $details['wp_state_token'] = $state->token;
        }
        return ['accepted' => $result->accepted, 'reasons' => $result->reasons, 'details' => $details];
    }

    /** @return array<string,mixed> */
    public function ingest(array $input): array
    {
        return $this->coordinator->execute($input)->toArray();
    }
}
