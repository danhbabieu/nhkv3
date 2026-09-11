<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Governance\ProposalState;
use NHK\Core\Infrastructure\Governance\{GovernanceRuntimeFactory, WpdbGovernanceQueueQuery};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Contracts\Governance\GovernanceQueueQuery;

final class GovernanceQueueAdminPage
{
    private const ACTIONS = ['submit', 'approve', 'reject', 'apply'];
    private const SORTS = ['created', 'updated', 'name', 'id', 'status'];
    private static $queryFactory;
    private static $actionServiceFactory;

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
        $filters = self::filtersFromGet();
        if (isset($filters['_diagnostic'])) {
            GovernanceQueueRenderer::render(['availability' => 'blocked', 'diagnostics' => [$filters['_diagnostic']], 'items' => [], 'filters' => $filters]);
            return;
        }
        $query = self::$queryFactory !== null ? (self::$queryFactory)() : new WpdbGovernanceQueueQuery($wpdb);
        GovernanceQueueRenderer::render($query->page($filters));
    }

    public static function handleAction(): void
    {
        self::requirePostAndView();
        $action = self::cleanKey($_POST['queue_action'] ?? '');
        if (!in_array($action, self::ACTIONS, true)) self::redirect(self::aggregate([self::failure('', $action, 'INVALID_ACTION')]), self::filtersFromPost());
        $capability = self::capabilityFor($action);
        if (!current_user_can($capability)) wp_die('Bạn không có quyền thực hiện thao tác này.', '', ['response' => 403]);
        $id = self::cleanText($_POST['proposal_id'] ?? '');
        $snapshot = self::snapshot(array_merge($_POST, ['_action' => $action]), $action);
        if (!UuidCodec::isValid($id) || $snapshot === null) self::redirect(self::aggregate([self::failure($id, $action, 'INVALID_INPUT')]), self::filtersFromPost());
        if ($action === 'reject' && isset($_POST['reject_reason'])) {
            $reason = sanitize_textarea_field((string) wp_unslash($_POST['reject_reason']));
            if ($reason !== '' && strlen($reason) > 500) self::redirect(self::aggregate([self::failure($id, $action, 'INVALID_REJECT_REASON')]), self::filtersFromPost());
        }
        $result = self::service()->execute($action, $id, $snapshot);
        self::redirect(self::aggregate([$result]), self::filtersFromPost());
    }

    public static function handleBulk(): void
    {
        self::requirePostAndView();
        $action = self::cleanKey($_POST['bulk_action'] ?? '');
        if (!in_array($action, self::ACTIONS, true)) self::redirect(self::aggregate([self::failure('', $action, 'INVALID_ACTION')]), self::filtersFromPost());
        if (!current_user_can(self::capabilityFor($action))) wp_die('Bạn không có quyền thực hiện thao tác này.', '', ['response' => 403]);
        $selectedIds = isset($_POST['selected_ids']) && is_array($_POST['selected_ids']) ? array_values(array_map(static fn ($id): string => self::cleanText($id), wp_unslash($_POST['selected_ids']))) : [];
        $rawSnapshots = isset($_POST['snapshots']) && is_array($_POST['snapshots']) ? wp_unslash($_POST['snapshots']) : [];
        $items = [];
        $invalid = [];
        foreach ($selectedIds as $id) {
            $raw = $rawSnapshots[$id] ?? null;
            if (!is_array($raw)) {
                $invalid[] = ['ok' => false, 'proposal_id' => $id, 'action' => $action, 'state' => null, 'reason' => 'INVALID_INPUT'];
                continue;
            }
            $snapshot = self::snapshot(array_merge($raw, ['_action' => $action]), $action);
            if (!UuidCodec::isValid($id) || $snapshot === null) {
                $invalid[] = ['ok' => false, 'proposal_id' => $id, 'action' => $action, 'state' => null, 'reason' => 'INVALID_INPUT'];
                continue;
            }
            $snapshot['proposal_id'] = $id; $items[] = $snapshot;
        }
        $validResult = $items === [] ? ['results' => []] : self::service()->bulk($action, $items);
        $results = array_merge($validResult['results'] ?? [], $invalid);
        if ($results === []) {
            self::redirect(['selected' => 0, 'succeeded' => 0, 'skipped' => 0, 'failed' => 1, 'failures' => [self::failure('', $action, 'NO_SELECTION')], 'skipped_items' => [], 'results' => []], self::filtersFromPost());
        }
        self::redirect(self::aggregate($results), self::filtersFromPost());
    }

    /** @return array<string,mixed> */
    private static function filtersFromGet(): array
    {
        $search = self::cleanText($_GET['search'] ?? '');
        $status = self::cleanKey($_GET['status'] ?? '');
        if ($status !== '' && !in_array($status, array_map(static fn (ProposalState $state): string => $state->value, ProposalState::cases()), true)) return ['_diagnostic' => 'INVALID_FILTER'];
        $type = self::cleanKey($_GET['type'] ?? '');
        if ($type !== '' && !WpdbGovernanceQueueQuery::isAllowedType($type)) return ['_diagnostic' => 'INVALID_FILTER'];
        $orderBy = self::cleanKey($_GET['order_by'] ?? 'updated'); if (!WpdbGovernanceQueueQuery::isAllowedOrderBy($orderBy)) return ['_diagnostic' => 'INVALID_FILTER'];
        $order = self::cleanKey($_GET['order'] ?? 'desc'); if (!in_array($order, ['asc', 'desc'], true)) return ['_diagnostic' => 'INVALID_FILTER'];
        return ['search' => $search, 'status' => $status, 'type' => $type, 'order_by' => $orderBy, 'order' => $order, 'page' => self::positiveInt($_GET['paged'] ?? $_GET['page'] ?? $_GET['page_number'] ?? 1), 'per_page' => min(100, self::positiveInt($_GET['per_page'] ?? 20))];
    }

    private static function requirePostAndView(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') wp_die('Thao tác này chỉ chấp nhận POST.', '', ['response' => 405]);
        if (!current_user_can('nhk_view_governance')) wp_die('Bạn không có quyền xem hàng đợi dữ liệu.', '', ['response' => 403]);
        check_admin_referer('nhk_governance_queue_action');
    }

    private static function service(): GovernanceQueueActionService
    {
        if (self::$actionServiceFactory !== null) return (self::$actionServiceFactory)();
        global $wpdb;
        $runtime = GovernanceRuntimeFactory::fromWordPress($wpdb);
        return new GovernanceQueueActionService(new \NHK\Core\Application\Governance\CanonicalGovernanceActionPort($runtime->proposals, $runtime->governance, $runtime->eligibility, $runtime->controlledApply), static fn (string $capability): bool => current_user_can($capability), static fn (): int => get_current_user_id(), $runtime->videoReconciliation);
    }

    public static function setTestQuery(GovernanceQueueQuery $query): void { self::$queryFactory = static fn (): GovernanceQueueQuery => $query; }
    public static function setTestActionService(mixed $service): void { self::$actionServiceFactory = is_callable($service) ? $service : static fn (): GovernanceQueueActionService => $service; }
    public static function resetTestSeams(): void { self::$queryFactory = null; self::$actionServiceFactory = null; }

    private static function capabilityFor(string $action): string { return in_array($action, ['approve', 'reject'], true) ? 'nhk_approve_proposals' : 'nhk_' . $action . '_proposals'; }
    private static function cleanKey(mixed $value): string { return is_scalar($value) ? sanitize_key((string) $value) : ''; }
    private static function cleanText(mixed $value): string { return is_scalar($value) ? sanitize_text_field(wp_unslash((string) $value)) : ''; }
    private static function positiveInt(mixed $value): int { return is_scalar($value) ? max(1, absint($value)) : 1; }
    /** @return array<string,mixed>|null */
    private static function snapshot(array $input, string $action = ''): ?array
    {
        if (!is_scalar($input['revision'] ?? null)) return null;
        $revision = absint($input['revision'] ?? 0); $state = self::cleanKey($input['state'] ?? '');
        if ($revision < 1 || ProposalState::tryFrom($state) === null) return null;
        $snapshot = ['revision' => $revision, 'state' => $state];
        if (in_array($action !== '' ? $action : (string) ($input['_action'] ?? ''), ['approve', 'apply'], true)) { $snapshot['content_fingerprint'] = self::cleanText($input['content_fingerprint'] ?? ''); $snapshot['dependency_fingerprint'] = self::cleanText($input['dependency_fingerprint'] ?? ''); }
        return $snapshot;
    }
    /** @param list<array<string,mixed>> $results */
    private static function aggregate(array $results): array
    {
        $succeeded = array_values(array_filter($results, static fn (array $result): bool => ($result['outcome'] ?? (($result['ok'] ?? false) ? 'success' : 'failed')) === 'success'));
        $skipped = array_values(array_filter($results, static fn (array $result): bool => ($result['outcome'] ?? 'failed') === 'skipped'));
        $failed = array_values(array_filter($results, static fn (array $result): bool => ($result['outcome'] ?? (($result['ok'] ?? false) ? 'success' : 'failed')) === 'failed'));
        return ['selected' => count($results), 'succeeded' => count($succeeded), 'skipped' => count($skipped), 'failed' => count($failed), 'failures' => $failed, 'skipped_items' => $skipped, 'results' => $results];
    }

    /** @return array<string,mixed> */
    private static function failure(string $id, string $action, string $reason): array
    {
        return ['ok' => false, 'outcome' => 'failed', 'proposal_id' => $id, 'action' => $action, 'state' => null, 'reason' => $reason];
    }

    /** @return array<string,mixed> */
    private static function filtersFromPost(): array
    {
        $search = self::cleanText($_POST['search'] ?? '');
        $status = self::cleanKey($_POST['status'] ?? '');
        $type = self::cleanKey($_POST['type'] ?? '');
        $orderBy = self::cleanKey($_POST['order_by'] ?? 'updated');
        $order = self::cleanKey($_POST['order'] ?? 'desc');
        if ($status !== '' && !in_array($status, array_map(static fn (ProposalState $state): string => $state->value, ProposalState::cases()), true)) $status = '';
        if ($type !== '' && !WpdbGovernanceQueueQuery::isAllowedType($type)) $type = '';
        if (!WpdbGovernanceQueueQuery::isAllowedOrderBy($orderBy)) $orderBy = 'updated';
        if (!in_array($order, ['asc', 'desc'], true)) $order = 'desc';
        return ['search' => $search, 'status' => $status, 'type' => $type, 'order_by' => $orderBy, 'order' => $order, 'paged' => self::positiveInt($_POST['paged'] ?? $_POST['page'] ?? 1), 'per_page' => min(100, self::positiveInt($_POST['per_page'] ?? 20))];
    }

    /** @param array<string,mixed> $filters */
    private static function redirect(array $result, array $filters = []): void
    {
        $encoded = rtrim(strtr(base64_encode((string) wp_json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
        $args = array_merge(['page' => 'nhk-v3-governance', 'nhk_queue_result' => $encoded], $filters);
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }
}
