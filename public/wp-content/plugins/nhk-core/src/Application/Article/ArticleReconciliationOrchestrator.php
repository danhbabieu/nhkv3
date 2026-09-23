<?php
declare(strict_types=1);

namespace NHK\Core\Application\Article;

/** Single bounded recovery loop for an existing Capture-owned WordPress Article. */
final class ArticleReconciliationOrchestrator
{
    public const MAX_PASSES = 3;

    /** @param callable(array<string,mixed>):array $load @param callable(array<string,mixed>):array $resolveIntent @param callable(array<string,mixed>):array $resolveSubject @param callable(array<string,mixed>):array $inspect @param callable(array<string,mixed>,list<\NHK\Core\Domain\Article\ArticleRemediationAction>):array $repair @param callable(array<string,mixed>):array $review @param callable(array<string,mixed>):array $publish @param callable(array<string,mixed>):array $verify */
    public function __construct(private $load, private $resolveIntent, private $resolveSubject, private $inspect, private $repair, private $review, private $publish, private $verify, private ?ArticleRemediationPlanner $planner = null) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function reconcile(array $input): array
    {
        $state = ($this->load)($input);
        $state['intent'] = ($this->resolveIntent)($state + $input);
        $state['subject_packet'] = ($this->resolveSubject)($state + $input);
        $state['subject_packet_supersession'] = $this->subjectPacketSupersession($state);
        $planner = $this->planner ?? new ArticleRemediationPlanner();
        $seen = [];
        $actions = [];
        $passes = [];
        for ($pass = 1; $pass <= self::MAX_PASSES; $pass++) {
            $state['inspection'] = ($this->inspect)($state + $input);
            $diagnostics = array_values(array_unique(array_map('strval', (array) ($state['inspection']['diagnostics'] ?? $state['inspection']['blockers'] ?? []))));
            if (is_array($state['subject_packet_supersession'])) {
                $diagnostics[] = 'SUBJECT_PACKET_SUPERSESSION';
                $diagnostics = array_values(array_unique($diagnostics));
            }
            $planned = $planner->plan($state + $state['inspection'], $diagnostics);
            $passActions = array_map(static fn ($action): array => $action->toArray(), $planned);
            $actions = array_merge($actions, $passActions);
            $passes[] = ['pass' => $pass, 'diagnostics' => $diagnostics, 'actions' => $passActions];
            if (($input['plan_only'] ?? false) === true) {
                return ['status' => 'PLANNED', 'passes' => $passes, 'actions' => $actions, 'review' => ['outcome' => $diagnostics === [] ? 'PASS' : 'REPAIR_REQUIRED', 'blockers' => $diagnostics], 'state' => $state];
            }
            if ($diagnostics === []) {
                $review = ($this->review)($state + $input);
                $outcome = strtoupper((string) ($review['outcome'] ?? 'SYSTEM_BLOCKED'));
                if ($outcome !== 'PASS') return $this->finish($state, $passes, $actions, $review, $outcome);
                $published = (($input['publish_requested'] ?? false) === true) ? ($this->publish)($state + ['review' => $review] + $input) : ['status' => 'NOT_REQUESTED'];
                $verified = ($this->verify)($state + ['review' => $review, 'published' => $published] + $input);
                return ['status' => (($verified['status'] ?? '') === 'verified' ? 'PASS' : 'SYSTEM_BLOCKED'), 'passes' => $passes, 'actions' => $actions, 'review' => $review, 'published' => $published, 'verification' => $verified, 'state' => $state];
            }
            $fingerprint = hash('sha256', json_encode($diagnostics, JSON_THROW_ON_ERROR));
            if (isset($seen[$fingerprint])) return $this->finish($state, $passes, $actions, ['outcome' => 'SYSTEM_BLOCKED', 'blockers' => ['REPEATED_REMEDIATION_BLOCKER'], 'root_cause' => $diagnostics], 'SYSTEM_BLOCKED');
            $seen[$fingerprint] = true;
            $safe = array_values(array_filter($planned, static fn ($action): bool => $action->autoRepairSafe));
            if ($safe === []) return $this->finish($state, $passes, $actions, ['outcome' => 'OWNER_REVIEW_REQUIRED', 'blockers' => $diagnostics], 'OWNER_REVIEW_REQUIRED');
            $state = array_replace($state, ($this->repair)($state + $input, $safe));
            $state['subject_packet_supersession'] = $this->subjectPacketSupersession($state);
        }
        return $this->finish($state, $passes, $actions, ['outcome' => 'SYSTEM_BLOCKED', 'blockers' => ['REMEDIATION_PASS_LIMIT_REACHED']], 'SYSTEM_BLOCKED');
    }

    /** @return array<string,mixed> */
    private function finish(array $state, array $passes, array $actions, array $review, string $status): array { return ['status' => $status, 'passes' => $passes, 'actions' => $actions, 'review' => $review, 'state' => $state]; }

    /** @return array<string,mixed>|null */
    private function subjectPacketSupersession(array $state): ?array
    {
        $current = is_array($state['subject_packet'] ?? null) ? $state['subject_packet'] : [];
        $persisted = is_array($state['subject_resolution_packet'] ?? null) ? $state['subject_resolution_packet'] : [];
        if ($current === [] || $persisted === []) return null;
        $identity = static fn (array $packet): array => [
            strtolower(trim((string) ($packet['status'] ?? ''))),
            strtolower(trim((string) ($packet['canonical_subject_id'] ?? $packet['id'] ?? ''))),
            strtolower(trim((string) ($packet['entity_type'] ?? $packet['type'] ?? ''))),
            strtolower(trim((string) ($packet['stable_key'] ?? ''))),
            (int) ($packet['revision'] ?? 0),
        ];
        if ($identity($current) === $identity($persisted)) return null;
        if (($current['status'] ?? '') !== 'resolved' || ($persisted['status'] ?? '') !== 'resolved') return null;
        return ['previous' => $persisted, 'current' => $current];
    }
}
