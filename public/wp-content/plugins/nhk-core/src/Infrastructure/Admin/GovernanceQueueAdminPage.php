<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Governance\ProposalState;
use NHK\Core\Infrastructure\Governance\{GovernanceRuntimeFactory, WpdbGovernanceQueueQuery};
use NHK\Core\Shared\Uuid\UuidCodec;

final class GovernanceQueueAdminPage
{
    private const ACTIONS = ['submit', 'approve', 'reject', 'apply'];
    private const SORTS = ['created', 'updated', 'name', 'id', 'status'];

    public static function register(): void
    {
        add_action('admin_post_nhk_governance_queue_action', [self::class, 'handleAction']);
        add_action('admin_post_nhk_governance_queue_bulk', [self::class, 'handleBulk']);
    }

    public static function render(): void
    {
        if (!current_user_can('nhk_view_governance')) wp_die('Bạn không có quyền xem hàng đợi dữ liệu.', '', ['response' => 403]);
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) { GovernanceQueueRenderer::render(['availability' => 'unavailable', 'items' => []]); return; }
        $query = new WpdbGovernanceQueueQuery($wpdb);
        GovernanceQueueRenderer::render($query->page(self::filtersFromGet()));
    }

    public static function handleAction(): void
    {
        self::requirePostAndView();
        $action = self::cleanKey($_POST['queue_action'] ?? '');
        if (!in_array($action, self::ACTIONS, true)) self::redirect(['selected' => 1, 'succeeded' => 0, 'failed' => 1, 'failures' => [['proposal_id' => '', 'reason' => 'INVALID_ACTION']]]);
        $capability = self::capabilityFor($action);
        if (!current_user_can($capability)) wp_die('Bạn không có quyền thực hiện thao tác này.', '', ['response' => 403]);
        $id = self::cleanText($_POST['proposal_id'] ?? '');
        $snapshot = self::snapshot($_POST);
        if (!UuidCodec::isValid($id) || $snapshot === null) self::redirect(['selected' => 1, 'succeeded' => 0, 'failed' => 1, 'failures' => [['proposal_id' => $id, 'reason' => 'INVALID_INPUT']]]);
        if ($action === 'reject' && isset($_POST['reject_reason'])) {
            $reason = sanitize_textarea_field((string) wp_unslash($_POST['reject_reason']));
            if ($reason !== '' && strlen($reason) > 500) self::redirect(['selected' => 1, 'succeeded' => 0, 'failed' => 1, 'failures' => [['proposal_id' => $id, 'reason' => 'INVALID_REJECT_REASON']]]);
        }
        $result = self::service()->execute($action, $id, $snapshot);
        self::redirect(self::aggregate([$result]));
    }

    public static function handleBulk(): void
    {
        self::requirePostAndView();
        $action = self::cleanKey($_POST['bulk_action'] ?? '');
        if (!in_array($action, self::ACTIONS, true)) self::redirect(['selected' => 0, 'succeeded' => 0, 'failed' => 1, 'failures' => [['proposal_id' => '', 'reason' => 'INVALID_ACTION']]]);
        if (!current_user_can(self::capabilityFor($action))) wp_die('Bạn không có quyền thực hiện thao tác này.', '', ['response' => 403]);
        $selectedIds = isset($_POST['selected_ids']) && is_array($_POST['selected_ids']) ? array_values(array_map(static fn ($id): string => self::cleanText($id), wp_unslash($_POST['selected_ids']))) : [];
        $rawSnapshots = isset($_POST['snapshots']) && is_array($_POST['snapshots']) ? wp_unslash($_POST['snapshots']) : [];
        $items = [];
        foreach ($selectedIds as $key => $id) {
            $raw = is_array($rawSnapshots[$id] ?? null) ? $rawSnapshots[$id] : [];
            if (!is_array($raw)) continue;
            $snapshot = self::snapshot($raw);
            if (!UuidCodec::isValid($id) || $snapshot === null) { $items[] = ['proposal_id' => $id, 'revision' => 0, 'state' => 'blocked']; continue; }
            $snapshot['proposal_id'] = $id; $items[] = $snapshot;
        }
        self::redirect(self::service()->bulk($action, $items));
    }

    /** @return array<string,mixed> */
    private static function filtersFromGet(): array
    {
        $search = sanitize_text_field(wp_unslash((string) ($_GET['search'] ?? '')));
        $status = self::cleanKey($_GET['status'] ?? '');
        if (!in_array($status, array_map(static fn (ProposalState $state): string => $state->value, ProposalState::cases()), true)) $status = '';
        $type = self::cleanKey($_GET['type'] ?? '');
        $orderBy = self::cleanKey($_GET['order_by'] ?? 'updated'); if (!in_array($orderBy, self::SORTS, true)) $orderBy = 'updated';
        $order = self::cleanKey($_GET['order'] ?? 'desc'); if (!in_array($order, ['asc', 'desc'], true)) $order = 'desc';
        return ['search' => $search, 'status' => $status, 'type' => $type, 'order_by' => $orderBy, 'order' => $order, 'page' => max(1, absint($_GET['paged'] ?? $_GET['page_number'] ?? 1)), 'per_page' => min(100, max(1, absint($_GET['per_page'] ?? 20)))];
    }

    private static function requirePostAndView(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_die('Thao tác này chỉ chấp nhận POST.', '', ['response' => 405]);
        if (!current_user_can('nhk_view_governance')) wp_die('Bạn không có quyền xem hàng đợi dữ liệu.', '', ['response' => 403]);
        check_admin_referer('nhk_governance_queue_action');
    }

    private static function service(): GovernanceQueueActionService
    {
        global $wpdb;
        $runtime = GovernanceRuntimeFactory::fromWordPress($wpdb);
        return new GovernanceQueueActionService(new \NHK\Core\Application\Governance\CanonicalGovernanceActionPort($runtime->proposals, $runtime->governance, $runtime->eligibility, $runtime->controlledApply), static fn (string $capability): bool => current_user_can($capability), static fn (): int => get_current_user_id());
    }

    private static function capabilityFor(string $action): string { return in_array($action, ['approve', 'reject'], true) ? 'nhk_approve_proposals' : 'nhk_' . $action . '_proposals'; }
    private static function cleanKey(mixed $value): string { return sanitize_key((string) $value); }
    private static function cleanText(mixed $value): string { return sanitize_text_field(wp_unslash((string) $value)); }
    /** @return array<string,mixed>|null */
    private static function snapshot(array $input): ?array
    {
        $revision = absint($input['revision'] ?? 0); $state = self::cleanKey($input['state'] ?? '');
        if ($revision < 1 || ProposalState::tryFrom($state) === null) return null;
        $snapshot = ['revision' => $revision, 'state' => $state];
        if (in_array((string) ($_POST['queue_action'] ?? $_POST['bulk_action'] ?? ''), ['approve', 'apply'], true)) { $snapshot['content_fingerprint'] = self::cleanText($input['content_fingerprint'] ?? ''); $snapshot['dependency_fingerprint'] = self::cleanText($input['dependency_fingerprint'] ?? ''); }
        return $snapshot;
    }
    /** @param list<array<string,mixed>> $results */
    private static function aggregate(array $results): array { $failures = array_values(array_filter($results, static fn (array $result): bool => ($result['ok'] ?? false) !== true)); return ['selected' => count($results), 'succeeded' => count($results) - count($failures), 'failed' => count($failures), 'failures' => $failures, 'results' => $results]; }
    /** @param array<string,mixed> $result */
    private static function redirect(array $result): void { $encoded = rtrim(strtr(base64_encode((string) wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '='); wp_safe_redirect(add_query_arg(['page' => 'nhk-v3-governance', 'nhk_queue_result' => $encoded], admin_url('admin.php'))); exit; }
}
