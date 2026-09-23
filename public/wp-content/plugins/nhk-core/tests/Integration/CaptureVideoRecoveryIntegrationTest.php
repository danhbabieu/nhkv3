<?php
declare(strict_types=1);

namespace NHK\Tests\Integration;

use NHK\Core\Application\Capture\CaptureCurrentOutcomeReducer;
use NHK\Core\Infrastructure\Migration\{AuthorityMigration002, EditorialCaptureMigration017, MediaMigration004};
use NHK\Tests\Support\{DbRecoveryFixtureFactory, TestDatabaseGuard};
use PHPUnit\Framework\TestCase;

final class CaptureVideoRecoveryIntegrationTest extends TestCase
{
    private ?DbRecoveryFixtureFactory $fixture = null;

    protected function setUp(): void
    {
        if (getenv('NHK_WP_TEST_PATH') !== 'public' || getenv('NHK_WP_TEST_DB') !== 'nhk_v3_test') {
            self::fail('Capture recovery integration requires NHK_WP_TEST_PATH=public and NHK_WP_TEST_DB=nhk_v3_test.');
        }
        require_once rtrim((string) getenv('NHK_WP_TEST_PATH'), '/') . '/wp-load.php';
        TestDatabaseGuard::selectTestDatabase();
        TestDatabaseGuard::requireTestDatabase();
        (new AuthorityMigration002())->up();
        (new EditorialCaptureMigration017())->up();
        (new MediaMigration004())->up();
        $this->fixture = new DbRecoveryFixtureFactory();
    }

    protected function tearDown(): void
    {
        $this->fixture?->cleanup();
        $this->fixture = null;
    }

    public function test_legacy_review_recovery_uses_same_capture_and_one_db_video_through_transport(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $before = $fixture->readCapture();

        $response = $fixture->transport()->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'nhk.capture.ingest', 'arguments' => $fixture->request()],
        ]);

        $result = $response['body']['result']['structuredContent'] ?? [];
        $after = $fixture->readCapture();
        self::assertSame(200, $response['status']);
        self::assertSame($fixture->captureId, $result['capture']['capture_id'] ?? null);
        self::assertSame($fixture->captureId, $after->captureId);
        self::assertGreaterThan($before->revision, $after->revision);
        self::assertSame('COMPLETE', $after->status);
        self::assertSame('CONTENT_COMPLETE', $after->diagnostics['completion']['children'][0]['content_state'] ?? null);
        self::assertSame($fixture->subjectId, $after->context['subject_resolution_packet']['canonical_subject_id'] ?? null);
        self::assertSame(1, $fixture->countVideos());
        self::assertNull(CaptureCurrentOutcomeReducer::failureCode($after));
        self::assertArrayNotHasKey('failure', $after->diagnostics);
        self::assertSame('COMPLETED', $after->phaseReceipts['VIDEO_ENRICHED']['latest']['status'] ?? null);
        self::assertContains('VIDEO_EDITORIAL_QUALITY_BLOCKED', $after->phaseReceipts['VIDEO_ENRICHED']['superseded_failure_codes'] ?? []);
        self::assertContains('VIDEO_EDITORIAL_QUALITY_BLOCKED', $after->phaseReceipts['CONTENT_PREPARATION']['superseded_failure_codes'] ?? []);
    }

    public function test_owner_persisted_then_crash_reuses_same_owner_on_retry(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $first = $fixture->transport(['owner_persisted_then_crash' => true])->dispatch($this->request($fixture));
        self::assertSame(1, $fixture->countVideos());
        self::assertNotSame('COMPLETE', $fixture->readCapture()->status);
        $second = $fixture->transport()->dispatch($this->request($fixture));
        self::assertSame('COMPLETE', $fixture->readCapture()->status);
        self::assertSame(1, $fixture->countVideos());
        self::assertSame($fixture->captureId, $second['body']['result']['structuredContent']['capture']['capture_id'] ?? null);
    }

    public function test_partial_receipt_crash_preserves_history_and_new_attempt_completes(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $fixture->transport(['partial_receipt_then_crash' => true])->dispatch($this->request($fixture));
        $first = $fixture->readCapture();
        $fixture->transport()->dispatch($this->request($fixture));
        $second = $fixture->readCapture();
        self::assertSame('COMPLETE', $second->status);
        self::assertGreaterThan($first->revision, $second->revision);
        self::assertSame(1, $fixture->countVideos());
        self::assertNotEmpty($second->phaseReceipts['SEMANTICS_RECONCILED']['attempts'] ?? []);
    }

    public function test_preexisting_external_identity_is_reused_without_duplicate(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $fixture->persistExistingVideo();
        $response = $fixture->transport()->dispatch($this->request($fixture));
        $capture = $fixture->readCapture();
        self::assertSame('COMPLETE', $capture->status);
        self::assertSame(1, $fixture->countVideos());
        self::assertSame($fixture->videoId, $capture->diagnostics['completion']['children'][0]['owner_id'] ?? null);
        self::assertFalse(($response['body']['result']['structuredContent']['retry']['code'] ?? null) === 'CAPTURE_RETRY_NOT_ALLOWED');
    }

    public function test_retry_after_complete_is_idempotent_readback(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $fixture->transport()->dispatch($this->request($fixture));
        $complete = $fixture->readCapture();
        $replay = $fixture->transport()->dispatch($this->request($fixture));
        $after = $fixture->readCapture();
        self::assertSame('COMPLETE', $after->status);
        self::assertSame($complete->captureId, $after->captureId);
        self::assertSame(1, $fixture->countVideos());
        self::assertGreaterThanOrEqual($complete->revision, $after->revision);
        self::assertSame($fixture->captureId, $replay['body']['result']['structuredContent']['capture']['capture_id'] ?? null);
    }

    public function test_failure_before_owner_commit_leaves_no_half_owner_then_retry_creates_one(): void
    {
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $fixture->transport(['before_owner_commit' => true])->dispatch($this->request($fixture));
        self::assertSame(0, $fixture->countVideos());
        $fixture->transport()->dispatch($this->request($fixture));
        self::assertSame('COMPLETE', $fixture->readCapture()->status);
        self::assertSame(1, $fixture->countVideos());
    }

    public function test_db_unique_external_identity_and_recovery_readback_hold_under_duplicate_setup(): void
    {
        global $wpdb;
        $fixture = $this->fixture;
        self::assertNotNull($fixture);
        $fixture->persistExistingVideo();
        $duplicate = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . $wpdb->prefix . 'nhk_videos (canonical_uuid,platform,external_video_id,canonical_url,title,metadata_json,thumbnail_media_uuid,state,revision,created_at,updated_at) VALUES (%s,%s,%s,%s,%s,%s,NULL,1,1,%s,%s)',
            \NHK\Core\Shared\Uuid\UuidCodec::toBinary(\NHK\Core\Shared\Uuid\UuidCodec::newV7()),
            $fixture->platform,
            $fixture->externalVideoId,
            'https://www.youtube.com/watch?v=' . $fixture->externalVideoId,
            'Integration recovery video',
            wp_json_encode(['subject_resolution_packet' => $fixture->readCapture()->context['subject_resolution_packet'] ?? [], 'integration_fixture' => true]),
            gmdate('Y-m-d H:i:s.u'),
            gmdate('Y-m-d H:i:s.u'),
        ));
        self::assertFalse($duplicate, 'DB unique external identity must reject the duplicate insert.');
        $fixture->transport()->dispatch($this->request($fixture));
        self::assertSame('COMPLETE', $fixture->readCapture()->status);
        self::assertSame(1, $fixture->countVideos());
    }

    private function request(DbRecoveryFixtureFactory $fixture): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'nhk.capture.ingest', 'arguments' => $fixture->request()]];
    }
}
