<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use DateTimeImmutable;
use NHK\Core\Application\Article\OwnerPublicationApplicationService;
use NHK\Core\Application\Governance\GovernanceCapabilities;
use NHK\Core\Contracts\Article\PublicationPrincipal;
use NHK\Core\Domain\Article\ArticlePublicationOutcome;
use NHK\Core\Infrastructure\Article\WpdbOwnerPublicationDecisionRepository;
use NHK\Core\Infrastructure\Migration\OwnerPublicationDecisionMigration013;
use NHK\Core\Infrastructure\WordPress\WpEditorialPostStore;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class OwnerPublicationOverrideIntegrationTest extends TestCase
{
    /** @var list<int> */
    private array $postIds = [];

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::markTestSkipped('Set NHK_WP_TEST_PATH=public for WordPress integration tests.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        require_once dirname(__DIR__, 2) . '/nhk-core.php';
        (new OwnerPublicationDecisionMigration013())->up();
        do_action('rest_api_init');
    }

    protected function tearDown(): void
    {
        global $wpdb;
        if (!TestDatabaseGuard::isInitialized($wpdb ?? null)) return;
        if ($this->postIds !== []) {
            $ids = implode(',', array_map('intval', $this->postIds));
            $wpdb->query("DELETE FROM {$wpdb->prefix}nhk_owner_publication_decisions WHERE wp_post_id IN ({$ids})");
            foreach ($this->postIds as $postId) wp_delete_post($postId, true);
        }
    }

    public function test_pass_publishes_an_isolated_inserted_draft_and_readback_confirms_publish(): void
    {
        $postId = $this->draft();
        $state = (new WpEditorialPostStore())->read($postId);
        self::assertNotNull($state);

        $result = $this->service()->request($postId, $state->token, ownerPublicationIntegrationEvidence(), 'integration-pass-' . $postId, $this->principal());

        self::assertSame(ArticlePublicationOutcome::PASS->value, $result['outcome'], wp_json_encode($result));
        self::assertSame('publish', $result['post']['status']);
        self::assertSame('publish', (new WpEditorialPostStore())->read($postId)?->status);
    }

    public function test_review_then_approved_exception_publishes_and_preserves_failed_diagnostic(): void
    {
        $postId = $this->draft();
        $state = (new WpEditorialPostStore())->read($postId);
        self::assertNotNull($state);
        $evidence = ownerPublicationIntegrationEvidence(['real_image_requirements_met' => false, 'real_image_requirements_met_status' => 'missing']);

        $review = $this->service()->review($postId, $state->token, $evidence, 'integration-review-' . $postId, $this->principal());
        self::assertSame(ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED->value, $review['outcome'], wp_json_encode($review));
        self::assertSame('draft', (new WpEditorialPostStore())->read($postId)?->status);

        $approved = $this->service()->approveAndPublish($postId, $state->token, $evidence, 'integration-review-' . $postId, $review['decision_id'], $this->principal(), 'Đăng.');
        self::assertSame('published_with_exceptions', $approved['final_outcome']);
        self::assertContains('REAL_IMAGE_INCOMPLETE', $approved['diagnostics']);
        self::assertSame('publish', (new WpEditorialPostStore())->read($postId)?->status);

        $retry = $this->service()->approveAndPublish($postId, $state->token, $evidence, 'integration-review-' . $postId, $review['decision_id'], $this->principal(), 'Đăng.');
        self::assertSame('published_with_exceptions', $retry['final_outcome']);
        self::assertSame('publish', (new WpEditorialPostStore())->read($postId)?->status);
    }

    public function test_system_blocked_never_offers_owner_override_or_publishes(): void
    {
        $postId = $this->draft();
        $state = (new WpEditorialPostStore())->read($postId);
        self::assertNotNull($state);
        $result = $this->service()->review($postId, $state->token, ownerPublicationIntegrationEvidence(['public_route_ready' => false]), 'integration-blocked-' . $postId, $this->principal());

        self::assertSame(ArticlePublicationOutcome::SYSTEM_BLOCKED->value, $result['outcome']);
        self::assertArrayNotHasKey('decision_id', $result);
        self::assertSame('draft', (new WpEditorialPostStore())->read($postId)?->status);
    }

    public function test_mcp_review_returns_pass_without_publishing_the_isolated_draft(): void
    {
        $users = get_users(['role' => 'administrator', 'number' => 1]);
        self::assertNotEmpty($users);
        GovernanceCapabilities::register();
        $administrator = get_role('administrator');
        self::assertNotNull($administrator);
        $administrator->add_cap('publish_posts');
        $administrator->add_cap('nhk_ingest_articles');
        $previousUser = get_current_user_id();
        wp_set_current_user((int) $users[0]->ID);
        try {
            $postId = $this->draft();
            $state = (new WpEditorialPostStore())->read($postId);
            self::assertNotNull($state);
            $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
            $request->set_header('MCP-Protocol-Version', '2026-07-28');
            $request->set_header('Mcp-Method', 'tools/call');
            $request->set_header('Mcp-Name', 'nhk.article.publish.review');
            $request->set_header('Content-Type', 'application/json');
            $request->set_header('Accept', 'application/json, text/event-stream');
            $request->set_body(wp_json_encode(['jsonrpc' => '2.0', 'id' => 701, 'method' => 'tools/call', 'params' => ['name' => 'nhk.article.publish.review', 'arguments' => ['post_id' => $postId, 'expected_state_token' => $state->token, 'idempotency_key' => 'mcp-review-' . $postId, 'evidence' => ownerPublicationIntegrationEvidence()], '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']]]));

            $response = rest_do_request($request);
            self::assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));
            self::assertSame('PASS', $response->get_data()['result']['structuredContent']['outcome'], (string) wp_json_encode($response->get_data()));
            self::assertSame('draft', (new WpEditorialPostStore())->read($postId)?->status);
        } finally {
            wp_set_current_user($previousUser);
        }
    }

    private function draft(): int
    {
        $slug = 'nhk-isolated-owner-publication-' . bin2hex(random_bytes(4));
        $postId = wp_insert_post(['post_title' => 'NHK isolated owner publication', 'post_name' => $slug, 'post_content' => 'Isolated integration content.', 'post_status' => 'draft', 'post_type' => 'post'], true);
        self::assertIsInt($postId);
        self::assertGreaterThan(0, $postId);
        $this->postIds[] = $postId;
        return $postId;
    }

    private function service(): OwnerPublicationApplicationService
    {
        return new OwnerPublicationApplicationService(new WpEditorialPostStore(), new WpdbOwnerPublicationDecisionRepository(), static fn (PublicationPrincipal $principal): bool => $principal->id === 'integration-owner', static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-03T10:00:00+00:00'));
    }

    private function principal(): PublicationPrincipal { return new PublicationPrincipal('integration-owner', 'mcp', 'integration-test'); }
}

/** @param array<string,mixed> $overrides @return array<string,mixed> */
function ownerPublicationIntegrationEvidence(array $overrides = []): array
{
    return array_replace(array_fill_keys(['research_acceptable','subject_resolved','duplicate_intent_handled','category_resolved','semantic_plan_complete','semantic_readback_verified','media_usage_complete','real_image_requirements_met','claim_compliance_acceptable','seo_projection_valid','internal_links_valid','structured_data_valid','public_route_ready','rendered_public_verification'], true), $overrides);
}
