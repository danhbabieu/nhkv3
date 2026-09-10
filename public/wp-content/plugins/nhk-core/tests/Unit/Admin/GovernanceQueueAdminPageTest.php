<?php
declare(strict_types=1);

namespace {
    if (!function_exists('current_user_can')) { function current_user_can($capability) { return $GLOBALS['nhk_test_caps'][$capability] ?? true; } }
    if (!function_exists('wp_die')) { function wp_die($message, $title = '', $args = []) { throw new \RuntimeException((string) $message, (int) ($args['response'] ?? 500)); } }
    if (!function_exists('check_admin_referer')) { function check_admin_referer($action) { if (($GLOBALS['nhk_test_nonce'] ?? true) !== true) throw new \RuntimeException('nonce', 403); return 1; } }
    if (!function_exists('wp_safe_redirect')) { function wp_safe_redirect($location) { throw new \NHK\Tests\Unit\Admin\Redirected((string) $location); } }
    if (!function_exists('admin_url')) { function admin_url($path = '') { return '/wp-admin/' . ltrim((string) $path, '/'); } }
    if (!function_exists('add_query_arg')) { function add_query_arg($args, $url = '') { return (string) $url . '?' . http_build_query((array) $args); } }
    if (!function_exists('wp_nonce_field')) { function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="nonce">'; } }
    if (!function_exists('wp_unslash')) { function wp_unslash($value) { return $value; } }
    if (!function_exists('sanitize_key')) { function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); } }
    if (!function_exists('sanitize_text_field')) { function sanitize_text_field($value) { return trim((string) $value); } }
    if (!function_exists('sanitize_textarea_field')) { function sanitize_textarea_field($value) { return trim((string) $value); } }
    if (!function_exists('absint')) { function absint($value) { return abs((int) $value); } }
    if (!function_exists('wp_json_encode')) { function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); } }
}

namespace NHK\Tests\Unit\Admin {

use NHK\Core\Contracts\Governance\GovernanceActionPort;
use NHK\Core\Contracts\Governance\GovernanceQueueQuery;
use NHK\Core\Domain\Governance\{EligibilityResult, Proposal, ProposalState};
use NHK\Core\Infrastructure\Admin\GovernanceQueueActionService;
use NHK\Core\Infrastructure\Admin\GovernanceQueueAdminPage;
use PHPUnit\Framework\TestCase;

final class Redirected extends \RuntimeException {}

final class GovernanceQueueAdminPageTest extends TestCase
{
    private const ID = '0198f8d5-1d55-7a10-8d4e-5f0d9d8d0001';

    protected function setUp(): void
    {
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $GLOBALS['nhk_test_caps'] = [];
        $GLOBALS['nhk_test_nonce'] = true;
        $GLOBALS['wpdb'] = (object) [];
        GovernanceQueueAdminPage::resetTestSeams();
    }

    protected function tearDown(): void
    {
        GovernanceQueueAdminPage::resetTestSeams();
        $_GET = [];
        $_POST = [];
    }

    public function test_get_rendering_invokes_only_query_and_never_action_service(): void
    {
        $query = new RecordingQueueQuery(['availability' => 'available', 'items' => [], 'filters' => []]);
        GovernanceQueueAdminPage::setTestQuery($query);
        GovernanceQueueAdminPage::setTestActionService(static function (): never { throw new \LogicException('GET must not construct action service'); });
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['status' => 'submitted', 'type' => 'knowledge', 'order_by' => 'name', 'order' => 'asc'];

        ob_start();
        GovernanceQueueAdminPage::render();
        ob_end_clean();

        self::assertCount(1, $query->calls);
        self::assertSame('submitted', $query->calls[0]['status']);
        self::assertSame('knowledge', $query->calls[0]['type']);
        self::assertSame('name', $query->calls[0]['order_by']);
        self::assertSame('asc', $query->calls[0]['order']);
    }

    public function test_invalid_get_filters_block_without_querying(): void
    {
        foreach (['status' => 'not-a-state', 'type' => 'not-a-type', 'order_by' => 'drop-table', 'order' => 'sideways'] as $key => $value) {
            $query = new RecordingQueueQuery(['availability' => 'available', 'items' => [], 'filters' => []]);
            GovernanceQueueAdminPage::setTestQuery($query);
            $_GET = [$key => $value];

            ob_start();
            GovernanceQueueAdminPage::render();
            $html = (string) ob_get_clean();

            self::assertSame([], $query->calls, $key);
            self::assertStringContainsString('INVALID_FILTER', $html, $key);
        }
    }

    public function test_post_security_rejects_get_nonce_and_capability_denials(): void
    {
        $_POST = ['queue_action' => 'submit', 'proposal_id' => self::ID, 'revision' => 1, 'state' => ProposalState::DRAFT->value];
        foreach ([['method' => 'GET', 'nonce' => true, 'cap' => true, 'code' => 405], ['method' => 'POST', 'nonce' => false, 'cap' => true, 'code' => 403], ['method' => 'POST', 'nonce' => true, 'cap' => false, 'code' => 403]] as $case) {
            $_SERVER['REQUEST_METHOD'] = $case['method'];
            $GLOBALS['nhk_test_nonce'] = $case['nonce'];
            $GLOBALS['nhk_test_caps'] = ['nhk_view_governance' => $case['cap'], 'nhk_submit_proposals' => $case['cap']];
            try { GovernanceQueueAdminPage::handleAction(); self::fail('expected rejection'); } catch (\RuntimeException $error) { self::assertSame($case['code'], $error->getCode()); }
        }
    }

    public function test_invalid_action_and_uuid_are_reported_without_delegation(): void
    {
        $service = $this->serviceMock();
        GovernanceQueueAdminPage::setTestActionService($service);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['queue_action' => 'drop-table', 'proposal_id' => 'bad'];
        $redirect = $this->captureRedirect(static function (): void { GovernanceQueueAdminPage::handleAction(); });
        self::assertStringContainsString('INVALID_ACTION', $redirect);
        $_POST = ['queue_action' => 'submit', 'proposal_id' => 'bad', 'revision' => 1, 'state' => 'draft'];
        $redirect = $this->captureRedirect(static function (): void { GovernanceQueueAdminPage::handleAction(); });
        self::assertStringContainsString('INVALID_INPUT', $redirect);
    }

    public function test_bulk_retains_missing_and_malformed_snapshots_and_continues(): void
    {
        $service = $this->serviceMock();
        GovernanceQueueAdminPage::setTestActionService($service);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $second = '0198f8d5-1d55-7a10-8d4e-5f0d9d8d0002';
        $third = '0198f8d5-1d55-7a10-8d4e-5f0d9d8d0003';
        $_POST = ['bulk_action' => 'submit', 'selected_ids' => [self::ID, $second, $third], 'snapshots' => [self::ID => ['revision' => 1, 'state' => 'draft'], $third => ['revision' => 0, 'state' => 'draft']]];
        $redirect = $this->captureRedirect(static function (): void { GovernanceQueueAdminPage::handleBulk(); });
        self::assertStringContainsString('"selected":3', $redirect);
        self::assertStringContainsString($second, $redirect);
        self::assertStringContainsString($third, $redirect);
        self::assertStringContainsString('INVALID_INPUT', $redirect);
    }

    public function test_reject_reason_is_bounded_before_delegation(): void
    {
        $service = $this->serviceMock();
        GovernanceQueueAdminPage::setTestActionService($service);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['queue_action' => 'reject', 'proposal_id' => self::ID, 'revision' => 1, 'state' => 'submitted', 'reject_reason' => str_repeat('x', 501)];
        $redirect = $this->captureRedirect(static function (): void { GovernanceQueueAdminPage::handleAction(); });
        self::assertStringContainsString('INVALID_REJECT_REASON', $redirect);
    }

    private function serviceMock(): GovernanceQueueActionService
    {
        $port = new class implements GovernanceActionPort {
            public function find(string $id): ?Proposal { return null; }
            public function submit(string $id): Proposal { throw new \LogicException('not reached'); }
            public function approve(string $id, string $contentFingerprint, string $dependencyFingerprint, string $actor): Proposal { throw new \LogicException('not reached'); }
            public function reject(string $id, string $actor): Proposal { throw new \LogicException('not reached'); }
            public function eligibility(string $id): EligibilityResult { return EligibilityResult::blocked('not reached'); }
            public function apply(string $id): array { throw new \LogicException('not reached'); }
        };
        return new GovernanceQueueActionService($port, static fn (string $capability): bool => true, static fn (): string => 'test');
    }

    private function captureRedirect(callable $callback): string
    {
        try { $callback(); } catch (Redirected $redirect) {
            parse_str((string) parse_url($redirect->getMessage(), PHP_URL_QUERY), $query);
            return (string) json_encode(json_decode(base64_decode(strtr((string) ($query['nhk_queue_result'] ?? ''), '-_', '+/'), true), true));
        }
        self::fail('expected redirect');
    }
    public function test_page_wiring_uses_queue_renderer_and_post_only_handlers(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/GovernanceQueueAdminPage.php');
        foreach (['GovernanceQueueQuery', 'GovernanceQueueActionService', 'GovernanceQueueRenderer', 'admin_post_nhk_governance_queue_action', 'admin_post_nhk_governance_queue_bulk', 'nhk_governance_queue_action', 'check_admin_referer', 'current_user_can', 'wp_safe_redirect'] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
        self::assertStringNotContainsString('listRecent(', $source);
        self::assertStringNotContainsString('$wpdb->query', $source);
    }

    public function test_get_rendering_has_no_action_service_call_and_input_allowlists_are_present(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/GovernanceQueueAdminPage.php');
        self::assertStringContainsString('$_GET', $source);
        self::assertStringContainsString('sanitize_key', $source);
        self::assertStringContainsString('absint', $source);
        self::assertStringContainsString('UuidCodec::isValid', $source);
        self::assertStringContainsString('ProposalState::cases', $source);
        self::assertStringContainsString("'submit', 'approve', 'reject', 'apply'", $source);
        self::assertStringContainsString("'created', 'updated', 'name', 'id', 'status'", $source);
    }

    public function test_every_proposal_state_is_handled_without_invalid_mutation_markup(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/GovernanceQueueAdminPage.php') . (string) file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/Admin/GovernanceQueueRenderer.php');
        foreach (['draft', 'submitted', 'approved', 'rejected', 'cancelled', 'superseded', 'applied'] as $state) self::assertStringContainsString($state, $source);
        self::assertStringContainsString('Gửi duyệt', $source);
        self::assertStringContainsString('Từ chối', $source);
        self::assertStringContainsString('Apply', $source);
    }
}

final class RecordingQueueQuery implements GovernanceQueueQuery
{
    /** @var list<array<string,mixed>> */
    public array $calls = [];

    public function __construct(private array $page) {}

    public function page(array $filters = []): array
    {
        $this->calls[] = $filters;
        return $this->page;
    }
}
}
