<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Application\Article\ArticleIngestCoordinator;
use NHK\Core\Application\Article\ArticleIngestPreflight;
use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Application\Media\ArticleMediaCoordinator;
use NHK\Core\Application\Article\ArticleResearchPreflight;
use NHK\Core\Application\Article\ArticleReconciliationOrchestrator;

class McpArticleIngestHandler
{
    public function __construct(
        private ArticleIngestCoordinator $coordinator,
        private ArticleIngestPreflight $preflight,
        private EditorialStateReader $editorial,
        private ?ArticleMediaCoordinator $articleMedia = null,
        private ?ArticleResearchPreflight $research = null,
        private $reconciliationFactory = null,
    ) {}

    /** @return array<string,mixed> */
    public function preflight(array $input): array
    {
        $reconciliation = $this->planReconciliation($input);
        if ($this->research !== null && trim((string) ($input['research_topic'] ?? '')) !== '') {
            $target = is_array($input['target_wp_post'] ?? null) ? $input['target_wp_post'] : [];
            $postId = preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', (string) ($target['endpoint_key'] ?? ''), $matches) === 1 ? (int) $matches[1] : 0;
            // Research preflight is also the public explicit-Media path. Do
            // not return before carrying the request's Article context into
            // inventory/planning; otherwise article_media.selected is lost
            // before ArticleMediaCoordinator can apply precedence over stale
            // historical usage.
            $articleContext = is_array($input['article_context'] ?? null) ? $input['article_context'] : [];
            if ($postId > 0) $articleContext['post_id'] = $postId;
            if (is_array($input['article_media'] ?? null)) $articleContext['article_media'] = $input['article_media'];
            if (is_array($input['media_context'] ?? null)) $articleContext = array_replace_recursive($articleContext, $input['media_context']);
            return array_replace($this->research->research((string) $input['research_topic'], is_array($input['research_subject'] ?? null) ? $input['research_subject'] : [], $articleContext)->toArray(), ['reconciliation' => $reconciliation]);
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
            if ($this->articleMedia !== null) {
                $mediaContext = is_array($input['media_context'] ?? null) ? $input['media_context'] : [];
                if (is_array($input['article_media'] ?? null)) $mediaContext['article_media'] = $input['article_media'];
                $details['media'] = $this->articleMedia->diagnoseForPost($state->postId, $mediaContext)->toArray();
                $details['acceptance_state'] = ['readiness' => $details['media']['state'] ?? 'UNKNOWN', 'blockers' => array_values(array_map(static fn (array $item): string => (string) ($item['code'] ?? 'UNKNOWN'), (array) ($details['media']['diagnostics'] ?? [])))];
            }
        }
        $media = is_array($details['media'] ?? null) ? $details['media'] : [];
        return [
            'accepted' => $result->accepted,
            'reasons' => $result->reasons,
            'details' => $details,
            'subject_resolution' => is_array($input['research_subject'] ?? null) ? $input['research_subject'] : null,
            'media_binding' => $media,
            'diagnostics' => array_values(array_merge((array) ($result->reasons ?? []), (array) ($media['diagnostics'] ?? []))),
            'blockers' => array_values(array_map('strval', (array) ($result->reasons ?? []))),
            'warnings' => [],
            'category_plan' => null,
            'readiness' => ['accepted' => $result->accepted, 'media_state' => $media['state'] ?? null],
            'state_token' => (string) ($details['wp_state_token'] ?? ''),
            'reconciliation' => $reconciliation,
        ];
    }

    /** @return array<string,mixed> */
    public function ingest(array $input): array
    {
        $reconciliation = $this->planReconciliation($input);
        $input = $this->applyReconciliationDesiredState($input, $reconciliation);
        $execution = $this->executeReconciliation($input);
        $input['article_reconciliation'] = $reconciliation;
        $result = $this->coordinator->execute($input)->toArray();
        $result['reconciliation'] = $reconciliation;
        if ($execution !== []) $result['reconciliation_execution'] = $execution;
        if ($this->articleMedia !== null && isset($result['wp_post_id']) && (int) $result['wp_post_id'] > 0) {
            $mediaContext = is_array($input['media_context'] ?? null) ? $input['media_context'] : [];
            if (is_array($input['article_media'] ?? null)) $mediaContext['article_media'] = $input['article_media'];
            $result['media'] = $this->articleMedia->diagnoseForPost((int) $result['wp_post_id'], $mediaContext)->toArray();
        }
        return $result;
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $plan @return array<string,mixed> */
    private function applyReconciliationDesiredState(array $input, array $plan): array
    {
        $state = is_array($plan['state'] ?? null) ? $plan['state'] : [];
        $packet = is_array($state['subject_packet'] ?? null) ? $state['subject_packet'] : [];
        if ($packet !== []) $input['subject_resolution_packet'] = $packet;
        $desiredMedia = is_array($state['desired_media'] ?? null) ? $state['desired_media'] : [];
        $selected = is_array($desiredMedia['selected'] ?? null) ? $desiredMedia['selected'] : [];
        if (isset($selected['media_id'], $selected['inline_primary']) && is_array($selected['inline_primary'])) {
            $desiredMedia['selected'] = [
                'featured_primary' => $selected + ['role' => 'featured_primary'],
                'inline_primary' => $selected['inline_primary'],
            ];
        }
        if ($desiredMedia !== []) $input['article_media'] = $desiredMedia;
        return $input;
    }

    /** @return array<string,mixed> */
    private function executeReconciliation(array &$input): array
    {
        if (strtolower(trim((string) ($input['intent'] ?? ''))) !== 'reconcile' || !is_callable($this->reconciliationFactory)) return [];
        $target = is_array($input['target_wp_post'] ?? null) ? $input['target_wp_post'] : [];
        $endpoint = trim((string) ($target['endpoint_key'] ?? ''));
        if (preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $endpoint, $matches) !== 1) return [];
        $factory = $this->reconciliationFactory;
        $orchestrator = $factory();
        if (!$orchestrator instanceof ArticleReconciliationOrchestrator) return [];
        $request = $input;
        $request['post_id'] = (int) $matches[1];
        $request['plan_only'] = false;
        $result = $orchestrator->reconcile($request);
        if (($result['status'] ?? '') === 'PASS' && is_array($result['state']['inspection'] ?? null)) {
            $token = trim((string) ($result['state']['inspection']['state_token'] ?? ''));
            if ($token !== '') $input['expected_editorial_state'] = ['state_token' => $token];
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function planReconciliation(array $input): array
    {
        if (!is_callable($this->reconciliationFactory)) return [];
        if (strtolower(trim((string) ($input['intent'] ?? ''))) === 'reconcile'
            && !array_key_exists('capture_id', $input)
            && !is_array($input['subject_resolution_packet'] ?? null)
            && !is_array($input['article_media'] ?? null)) return [];
        $target = is_array($input['target_wp_post'] ?? null) ? $input['target_wp_post'] : [];
        $endpoint = trim((string) ($target['endpoint_key'] ?? ''));
        if (preg_match('/^[1-9][0-9]*:([1-9][0-9]*)$/', $endpoint, $matches) !== 1) return [];
        $request = $input;
        $request['post_id'] = (int) $matches[1];
        $request['plan_only'] = true;
        $factory = $this->reconciliationFactory;
        $orchestrator = $factory();
        return $orchestrator instanceof ArticleReconciliationOrchestrator ? $orchestrator->reconcile($request) : [];
    }
}
