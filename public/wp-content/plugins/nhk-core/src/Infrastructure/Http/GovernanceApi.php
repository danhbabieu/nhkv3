<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Http;

use NHK\Core\Application\Governance\{GovernanceService, WordPressGovernanceAuthorizer};
use NHK\Core\Application\Governance\ControlledApplyService;
use NHK\Core\Application\Governance\ProposalEligibilityService;
use NHK\Core\Contracts\Shared\TransactionManager;
use NHK\Core\Domain\Governance\Proposal;
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Governance\{WpdbAuditSink, WpdbProposalRepository};
use NHK\Core\Shared\Uuid\UuidCodec;

final class GovernanceApi
{
    private GovernanceService $governance;

    public function __construct(?GovernanceService $governance = null, private ?ProposalEligibilityService $eligibility = null, private ?ControlledApplyService $apply = null)
    {
        $this->governance = $governance ?? new GovernanceService(new WpdbProposalRepository(), new WpdbAuditSink(), new WpdbTransactionManager(), new WordPressGovernanceAuthorizer());
    }

    public function register(): void
    {
        register_rest_route('nhk/v1', '/governance/proposals', ['methods' => 'POST', 'permission_callback' => fn (): bool => current_user_can('nhk_create_proposals'), 'callback' => fn (\WP_REST_Request $request) => $this->create($request)]);
        register_rest_route('nhk/v1', '/governance/proposals/(?P<id>[0-9a-f-]{36})/submit', ['methods' => 'POST', 'permission_callback' => fn (): bool => current_user_can('nhk_submit_proposals'), 'callback' => fn (\WP_REST_Request $request) => $this->transition($request, 'submit')]);
        register_rest_route('nhk/v1', '/governance/proposals/(?P<id>[0-9a-f-]{36})/approve', ['methods' => 'POST', 'permission_callback' => fn (): bool => current_user_can('nhk_approve_proposals'), 'callback' => fn (\WP_REST_Request $request) => $this->transition($request, 'approve')]);
        register_rest_route('nhk/v1', '/governance/proposals/(?P<id>[0-9a-f-]{36})/reject', ['methods' => 'POST', 'permission_callback' => fn (): bool => current_user_can('nhk_approve_proposals'), 'callback' => fn (\WP_REST_Request $request) => $this->transition($request, 'reject')]);
        register_rest_route('nhk/v1', '/governance/proposals/(?P<id>[0-9a-f-]{36})/eligibility', ['methods' => 'GET', 'permission_callback' => fn (): bool => current_user_can('nhk_view_governance'), 'callback' => fn (\WP_REST_Request $request) => $this->eligibility($request)]);
        register_rest_route('nhk/v1', '/governance/proposals/(?P<id>[0-9a-f-]{36})/apply', ['methods' => 'POST', 'permission_callback' => fn (): bool => current_user_can('nhk_apply_proposals'), 'callback' => fn (\WP_REST_Request $request) => $this->apply($request)]);
    }

    private function eligibility(\WP_REST_Request $request): array|\WP_Error
    {
        if (!$this->eligibility) return new \WP_Error('nhk_governance_unavailable', 'Eligibility service is not configured.', ['status' => 503]);
        try { $result = $this->eligibility->check((string) $request['id']); return ['proposal_id' => (string) $request['id'], 'ready' => $result->ready, 'reasons' => $result->reasons]; } catch (\Throwable $error) { return $this->error($error); }
    }

    private function apply(\WP_REST_Request $request): array|\WP_Error
    {
        if (!$this->apply) return new \WP_Error('nhk_governance_unavailable', 'Controlled Apply service is not configured.', ['status' => 503]);
        try { return $this->apply->apply((string) $request['id']); } catch (\Throwable $error) { return $this->error($error); }
    }

    private function create(\WP_REST_Request $request): array|\WP_Error
    {
        try {
            $body = $request->get_json_params();
            $body = is_array($body) ? $body : [];
            $id = UuidCodec::newV7();
            $operation = (string) ($body['operation'] ?? ''); $entityType = (string) ($body['entity_type'] ?? ''); $subjectId = (string) ($body['subject_id'] ?? '');
            if ($subjectId === '' && in_array($operation, ['create', 'ingest', 'relation_create'], true)) $subjectId = $entityType !== '' ? $entityType : 'relation';
            $proposal = new Proposal($id, $subjectId, $operation, is_array($body['payload'] ?? null) ? $body['payload'] : [], (string) ($body['content_fingerprint'] ?? ''), max(1, (int) ($body['expected_revision'] ?? 1)), (string) ($body['dependency_fingerprint'] ?? ''), actor: (string) get_current_user_id(), idempotencyKey: (string) ($body['idempotency_key'] ?? ''), targetUuid: isset($body['target_uuid']) ? (string) $body['target_uuid'] : null, entityType: $entityType);
            return $this->serialize($this->governance->create($proposal));
        } catch (\Throwable $error) { return $this->error($error); }
    }

    private function transition(\WP_REST_Request $request, string $operation): array|\WP_Error
    {
        try {
            $id = (string) $request['id'];
            $body = $request->get_json_params(); $body = is_array($body) ? $body : [];
            $proposal = match ($operation) {
                'submit' => $this->governance->submit($id),
                'reject' => $this->governance->reject($id, (string) get_current_user_id()),
                'approve' => $this->governance->approve($id, (string) ($body['content_fingerprint'] ?? ''), (string) ($body['dependency_fingerprint'] ?? ''), (string) get_current_user_id()),
            };
            return $this->serialize($proposal);
        } catch (\Throwable $error) { return $this->error($error); }
    }

    private function serialize(Proposal $proposal): array { return ['id' => $proposal->id, 'subject_id' => $proposal->subjectId, 'entity_type' => $proposal->entityType, 'operation' => $proposal->operation, 'payload' => $proposal->payload, 'state' => $proposal->state->value, 'expected_revision' => $proposal->expectedRevision, 'revision' => $proposal->revision, 'idempotency_key' => $proposal->idempotencyKey, 'target_uuid' => $proposal->targetUuid]; }
    private function error(\Throwable $error): \WP_Error { $status = $error instanceof \NHK\Core\Governance\Exception\GovernancePermissionDenied ? 403 : ($error instanceof \NHK\Core\Governance\Exception\ProposalNotFound ? 404 : 400); return new \WP_Error('nhk_governance_error', $error->getMessage(), ['status' => $status]); }
}
