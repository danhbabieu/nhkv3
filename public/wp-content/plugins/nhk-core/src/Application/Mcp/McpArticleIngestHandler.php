<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Article\ArticleIngestCoordinator;
use NHK\Core\Application\Article\ArticleIngestPreflight;
use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Application\Media\ArticleMediaCoordinator;
use NHK\Core\Application\Article\ArticleResearchPreflight;

class McpArticleIngestHandler
{
    public function __construct(
        private ArticleIngestCoordinator $coordinator,
        private ArticleIngestPreflight $preflight,
        private EditorialStateReader $editorial,
        private ?ArticleMediaCoordinator $articleMedia = null,
        private ?ArticleResearchPreflight $research = null,
    ) {}

    /** @return array<string,mixed> */
    public function preflight(array $input): array
    {
        if ($this->research !== null && trim((string) ($input['research_topic'] ?? '')) !== '') {
            $target = is_array($input['target_wp_post'] ?? null) ? $input['target_wp_post'] : [];
            $postId = preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', (string) ($target['endpoint_key'] ?? ''), $matches) === 1 ? (int) $matches[1] : 0;
            return $this->research->research((string) $input['research_topic'], is_array($input['research_subject'] ?? null) ? $input['research_subject'] : [], $postId > 0 ? ['post_id' => $postId] : [])->toArray();
        }
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
            if ($this->articleMedia !== null) $details['media'] = $this->articleMedia->diagnoseForPost($state->postId, is_array($input['media_context'] ?? null) ? $input['media_context'] : ['subject' => $state->title])->toArray();
        }
        return ['accepted' => $result->accepted, 'reasons' => $result->reasons, 'details' => $details];
    }

    /** @return array<string,mixed> */
    public function ingest(array $input): array
    {
        $result = $this->coordinator->execute($input)->toArray();
        if ($this->articleMedia !== null && isset($result['wp_post_id']) && (int) $result['wp_post_id'] > 0) $result['media'] = $this->articleMedia->diagnoseForPost((int) $result['wp_post_id'], is_array($input['media_context'] ?? null) ? $input['media_context'] : [])->toArray();
        return $result;
    }
}
