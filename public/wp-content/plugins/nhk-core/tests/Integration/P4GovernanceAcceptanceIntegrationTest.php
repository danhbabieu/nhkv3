<?php
declare(strict_types=1);

namespace NHKTests\Integration;

use NHK\Core\Application\Governance\{GovernanceCapabilities, GovernanceService, ProposalEligibilityService};
use NHK\Core\Contracts\Governance\EligibilityReader;
use NHK\Core\Domain\Governance\{DependencyGraph, Proposal, ProposalState};
use NHK\Core\Infrastructure\Database\WpdbTransactionManager;
use NHK\Core\Infrastructure\Governance\{WpdbAuditSink, WpdbDependencyRepository, WpdbProposalRepository};
use NHK\Core\Governance\Exception\{DependencyCycle, GovernancePermissionDenied};
use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Tests\Support\TestDatabaseGuard;
use PHPUnit\Framework\TestCase;

final class P4GovernanceAcceptanceIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') === false) self::fail('P4 acceptance requires NHK_WP_TEST_PATH=public; no mandatory skip is allowed.');
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach (['nhk_audit_events', 'nhk_apply_attempts', 'nhk_proposal_approvals', 'nhk_proposal_dependencies', 'nhk_proposals'] as $table) {
            $wpdb->query('DELETE FROM ' . $wpdb->prefix . $table);
        }
    }

    public function test_lifecycle_is_transactional_and_supersede_keeps_canonical_replacement_id(): void
    {
        $service = $this->service();

        $rejected = $service->create($this->proposal('reject'));
        self::assertSame(ProposalState::REJECTED, $service->reject($rejected->id, 'reviewer')->state);

        $cancelled = $service->create($this->proposal('cancel'));
        $service->submit($cancelled->id);
        self::assertSame(ProposalState::CANCELLED, $service->cancel($cancelled->id, 'editor')->state);

        $original = $service->create($this->proposal('supersede-original'));
        $replacement = $service->create($this->proposal('supersede-replacement'));
        $superseded = $service->supersede($original->id, $replacement->id, 'editor');
        self::assertSame(ProposalState::SUPERSEDED, $superseded->state);
        self::assertSame($replacement->id, (new WpdbProposalRepository())->find($original->id)?->supersededByProposalId);

        global $wpdb;
        $events = $wpdb->get_col($wpdb->prepare('SELECT event_type FROM ' . $wpdb->prefix . 'nhk_audit_events WHERE object_type=%s ORDER BY id', 'proposal'));
        self::assertContains('ProposalRejected', $events);
        self::assertContains('ProposalCancelled', $events);
        self::assertContains('ProposalSuperseded', $events);
    }

    public function test_dependencies_are_persisted_idempotently_and_transitive_cycles_are_rejected(): void
    {
        $repo = new WpdbProposalRepository();
        $service = $this->service();
        $root = $service->create($this->proposal('dependency-root'));
        $middle = $service->create($this->proposal('dependency-middle'));
        $leaf = $service->create($this->proposal('dependency-leaf'));
        $dependencies = new WpdbDependencyRepository();
        $graph = new DependencyGraph($dependencies);

        $graph->add($root->id, $middle->id);
        $graph->add($middle->id, $leaf->id);
        $graph->add($root->id, $middle->id);
        self::assertSame([$middle->id], $dependencies->directDependencies($root->id));
        self::assertSame([$middle->id, $leaf->id], $graph->closure($root->id));

        $this->expectException(DependencyCycle::class);
        $graph->add($leaf->id, $root->id);
    }

    public function test_eligibility_reports_dependency_and_revision_reason_codes(): void
    {
        $service = $this->service();
        $dependency = $service->create($this->proposal('eligibility-dependency'));
        $proposal = $service->create($this->proposal('eligibility-root', 'target-1'));
        $service->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'reviewer');
        $graph = new DependencyGraph(new WpdbDependencyRepository());
        $graph->add($proposal->id, $dependency->id);
        $reader = new class implements EligibilityReader {
            public int $revision = 1;
            public bool $dependencyApplied = false;
            public function isApplied(string $dependencyUuid): bool { return $this->dependencyApplied; }
            public function targetRevision(string $targetUuid): ?int { return $this->revision; }
            public function targetExists(string $targetUuid): bool { return true; }
        };
        $eligibility = new ProposalEligibilityService(new WpdbProposalRepository(), $graph, $reader);

        $blocked = $eligibility->check($proposal->id);
        self::assertFalse($blocked->ready);
        self::assertSame(['DEPENDENCY_NOT_APPLIED'], $blocked->reasons);
        $reader->dependencyApplied = true;
        self::assertTrue($eligibility->check($proposal->id)->ready);
        $reader->revision = 2;
        self::assertSame(['TARGET_REVISION_CHANGED'], $eligibility->check($proposal->id)->reasons);
    }

    public function test_capability_registration_denial_and_wordpress_editorial_bypass(): void
    {
        if (get_role('administrator') === null) add_role('administrator', 'Administrator', []);
        GovernanceCapabilities::register();
        $administrator = get_role('administrator');
        self::assertNotNull($administrator);
        foreach (GovernanceCapabilities::ALL as $capability) self::assertTrue($administrator->has_cap($capability));

        $denying = new class implements \NHK\Core\Contracts\Governance\GovernanceAuthorizer {
            public function require(string $capability): void { throw new GovernancePermissionDenied($capability); }
        };
        $this->expectException(GovernancePermissionDenied::class);
        (new GovernanceService(new WpdbProposalRepository(), null, null, $denying))->create($this->proposal('denied'));
    }

    public function test_wp_post_editorial_write_does_not_require_governance(): void
    {
        wp_set_current_user(0);
        $postId = wp_insert_post(['post_title' => 'P4 editorial bypass', 'post_content' => 'WordPress owns this body.', 'post_status' => 'draft'], true);
        self::assertIsInt($postId);
        self::assertGreaterThan(0, $postId);
        self::assertSame('WordPress owns this body.', get_post_field('post_content', $postId));
        wp_delete_post($postId, true);
    }

    public function test_governance_audit_is_durable_and_redacts_sensitive_context(): void
    {
        global $wpdb;
        $service = $this->service();
        $proposal = $service->create($this->proposal('audit'));
        $service->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, 'reviewer');
        $audit = new WpdbAuditSink();
        $audit->recordEvent('SensitiveProbe', 'probe', $proposal->id, null, ['api_token' => 'do-not-store', 'raw_content' => 'do-not-store-body']);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT event_type,context_json FROM ' . $wpdb->prefix . 'nhk_audit_events WHERE object_key=%s ORDER BY id', $proposal->id), ARRAY_A);
        self::assertSame(['ProposalCreated', 'ProposalApproved', 'SensitiveProbe'], array_column($rows, 'event_type'));
        self::assertStringContainsString('[REDACTED]', (string) $rows[2]['context_json']);
        self::assertStringNotContainsString('do-not-store', (string) $rows[2]['context_json']);
    }

    private function service(): GovernanceService
    {
        return new GovernanceService(new WpdbProposalRepository(), new WpdbAuditSink(), new WpdbTransactionManager());
    }

    private function proposal(string $key, string $subject = 'subject'): Proposal
    {
        return new Proposal(UuidCodec::newV7(), $subject . '-' . $key, 'rename', ['name' => $key], 'content-' . $key, 1, 'dependencies-' . $key, ProposalState::DRAFT, 'author', null, null, 'idempotency-' . $key, 1, null, null, null, 'brand');
    }
}
