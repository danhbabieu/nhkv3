<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticlePublicationContinuationCommand;
use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Contracts\Capture\CaptureRepository;
use NHK\Core\Domain\Article\EditorialPostState;
use NHK\Core\Domain\Capture\CaptureRecord;
use PHPUnit\Framework\TestCase;

final class ArticlePublicationContinuationCommandTest extends TestCase
{
    private const CAPTURE_ID = '01a08663-6d6b-77f0-b05c-b66ced29e606';
    private const TOKEN = 'c9ee140b1c869fed08fa87f65b11dc2cc59df974980f996fdfada2ab4da09422';
    private const DOC_VERSION = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MANIFEST_HASH = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_review_reads_existing_capture_and_current_token_then_calls_review_ability(): void
    {
        $calls = [];
        $command = $this->command($calls);

        $result = $command->execute($this->input('review'));

        self::assertSame('OWNER_REVIEW_REQUIRED', $result['outcome']);
        self::assertSame(self::CAPTURE_ID, $calls[0]['arguments']['evidence']['capture_id']);
        self::assertSame(331, $calls[0]['arguments']['post_id']);
        self::assertSame(self::TOKEN, $calls[0]['arguments']['expected_state_token']);
        self::assertSame('nhk.article.publish.review', $calls[0]['tool']);
    }

    public function test_approve_requires_explicit_affirmation_and_calls_canonical_ability(): void
    {
        $calls = [];
        $command = $this->command($calls, ['outcome' => 'PASS', 'post' => ['status' => 'publish', 'permalink' => 'https://demo.test/?p=331'], 'state_token' => 'published-token', 'publication_receipt' => ['outcome' => 'COMPLETED']], null, null, static fn (string $url): array => ['status' => 'verified', 'url' => $url, 'http_status' => 200]);

        $result = $command->execute($this->input('approve') + ['decision_id' => 'decision-331', 'affirmation' => 'Đăng']);

        self::assertSame('PASS', $result['outcome']);
        self::assertSame('nhk.article.publish.approve', $calls[0]['tool']);
        self::assertSame('Đăng', $calls[0]['arguments']['affirmation']);
    }

    public function test_publish_is_not_invoked_for_system_blocked_review(): void
    {
        $calls = [];
        $command = $this->command($calls, ['outcome' => 'SYSTEM_BLOCKED', 'diagnostics' => ['SEMANTIC_PLAN_INCOMPLETE']]);

        $result = $command->execute($this->input('review'));

        self::assertSame('SYSTEM_BLOCKED', $result['outcome']);
        self::assertCount(1, $calls);
        self::assertSame('nhk.article.publish.review', $calls[0]['tool']);
    }

    public function test_stale_documentation_checkpoint_is_rejected_before_ability_invocation(): void
    {
        $calls = [];
        $command = $this->command($calls);
        $input = $this->input('review');
        $input['evidence']['documentation_checkpoint']['manifest_hash'] = str_repeat('c', 64);

        $result = $command->execute($input);

        self::assertSame('SYSTEM_BLOCKED', $result['outcome']);
        self::assertContains('DOCUMENTATION_CHECKPOINT_STALE', $result['diagnostics']);
        self::assertCount(0, $calls);
    }

    public function test_capture_article_mismatch_is_rejected_without_creating_any_record(): void
    {
        $calls = [];
        $captures = new ContinuationCommandCaptureRepository($this->capture(332));
        $command = $this->command($calls, null, $captures);

        $result = $command->execute($this->input('review'));

        self::assertSame('SYSTEM_BLOCKED', $result['outcome']);
        self::assertContains('CAPTURE_ARTICLE_BINDING_INVALID', $result['diagnostics']);
        self::assertSame(0, $captures->created);
        self::assertCount(0, $calls);
    }

    public function test_publish_requires_rendered_public_read_back_after_native_pass(): void
    {
        $calls = [];
        $command = $this->command($calls, ['outcome' => 'PASS', 'post' => ['status' => 'publish', 'permalink' => 'https://demo.test/?p=331'], 'state_token' => 'new-token', 'publication_receipt' => ['outcome' => 'COMPLETED']], null, null, static fn (string $url): array => ['status' => 'verified', 'url' => $url, 'http_status' => 200]);

        $result = $command->execute($this->input('publish'));

        self::assertSame('PASS', $result['outcome']);
        self::assertSame('verified', $result['public_rendered_readback']['status']);
        self::assertSame('nhk.article.publish', $calls[0]['tool']);
    }

    public function test_cli_is_discoverable_and_contains_no_low_level_publish_writer(): void
    {
        $cli = dirname(__DIR__, 2) . '/bin/nhk-core-publication.php';
        self::assertFileExists($cli);
        $source = (string) file_get_contents($cli);
        $implementation = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Application/Article/ArticlePublicationContinuationCommand.php');
        self::assertStringContainsString('nhk.article.publish.review', $implementation);
        self::assertStringContainsString('nhk.article.publish.approve', $implementation);
        self::assertStringContainsString('nhk.article.publish', $implementation);
        self::assertStringContainsString('ArticlePublicationContinuationCommand', $source);
        self::assertStringContainsString('RenderedArticleVerifier', $source);
        self::assertStringNotContainsString('wp_update_post', $source);
        self::assertStringNotContainsString('post_status', $source);
    }

    public function test_run_requires_owner_confirmation_before_approve_and_preserves_same_idempotency_key(): void
    {
        $calls = [];
        $command = $this->command($calls, ['outcome' => 'OWNER_REVIEW_REQUIRED', 'decision_id' => 'decision-331', 'diagnostics' => ['OWNER_REVIEW_REQUIRED']]);

        $result = $command->execute($this->input('run'));

        self::assertSame('OWNER_REVIEW_REQUIRED', $result['outcome']);
        self::assertContains('OWNER_AFFIRMATION_REQUIRED', $result['diagnostics']);
        self::assertCount(1, $calls);
        self::assertSame('publication-331-run', $calls[0]['arguments']['idempotency_key']);
    }

    public function test_stale_explicit_state_token_is_rejected_before_review(): void
    {
        $calls = [];
        $command = $this->command($calls);
        $input = $this->input('review') + ['expected_state_token' => str_repeat('0', 64)];

        $result = $command->execute($input);

        self::assertSame('SYSTEM_BLOCKED', $result['outcome']);
        self::assertContains('EDITORIAL_CAS_REQUIRED', $result['diagnostics']);
        self::assertCount(0, $calls);
    }

    public function test_missing_governance_and_publication_evidence_is_fail_closed(): void
    {
        $calls = [];
        $command = $this->command($calls);
        $input = $this->input('review');
        unset($input['evidence']['governance']);

        $result = $command->execute($input);

        self::assertSame('SYSTEM_BLOCKED', $result['outcome']);
        self::assertSame(['PUBLICATION_EVIDENCE_REQUIRED'], $result['diagnostics']);
        self::assertCount(0, $calls);
    }


    /** @param array<int,array<string,mixed>> $calls @param array<string,mixed>|null $abilityResult @param callable(string):array<string,mixed>|null $publicReadBack */
    private function command(array &$calls, ?array $abilityResult = null, ?CaptureRepository $captures = null, ?EditorialStateReader $articles = null, ?callable $publicReadBack = null): ArticlePublicationContinuationCommand
    {
        $captures ??= new ContinuationCommandCaptureRepository($this->capture());
        $articles ??= new class implements EditorialStateReader {
            public function read(int $postId): ?EditorialPostState { return new EditorialPostState($postId, '1:' . $postId, 'post', 'draft', 'Westminster', 'body', '', '', 'https://demo.test/?p=' . $postId, 334, 3); }
        };
        $abilityResult ??= ['outcome' => 'OWNER_REVIEW_REQUIRED', 'decision_id' => 'decision-331', 'diagnostics' => ['OWNER_REVIEW_REQUIRED']];
        return new ArticlePublicationContinuationCommand(
            $captures,
            $articles,
            static function (string $tool, array $arguments) use (&$calls, $abilityResult): array { $calls[] = ['tool' => $tool, 'arguments' => $arguments]; return $abilityResult; },
            static fn (): array => ['documentation_version' => self::DOC_VERSION, 'manifest_hash' => self::MANIFEST_HASH],
            $publicReadBack,
        );
    }

    /** @return array<string,mixed> */
    private function input(string $operation): array
    {
        return [
            'operation' => $operation,
            'capture_id' => self::CAPTURE_ID,
            'article_id' => 331,
            'idempotency_key' => 'publication-331-' . $operation,
            'evidence' => [
                'capture_id' => self::CAPTURE_ID,
                'article_id' => 331,
                'editorial_state_token' => self::TOKEN,
                'semantic_revision' => 12,
                'documentation_checkpoint' => ['documentation_version' => self::DOC_VERSION, 'manifest_hash' => self::MANIFEST_HASH],
                'current_publication_review' => ['status' => 'reviewed'],
                'claim_compliance' => ['status' => 'PASS'],
                'seo_projection' => ['status' => 'PASS'],
                'structured_data' => ['status' => 'PASS'],
                'public_identity' => ['status' => 'PASS'],
                'media_usage' => ['status' => 'PASS'],
                'governance' => ['status' => 'APPLIED'],
            ],
        ];
    }

    private function capture(int $articleId = 331): CaptureRecord
    {
        return new CaptureRecord(self::CAPTURE_ID, 'capture-331', str_repeat('1', 64), 'READY_FOR_PUBLICATION', 'PARTIAL', $articleId, self::TOKEN, [], ['documentation_checkpoint' => ['documentation_version' => self::DOC_VERSION, 'manifest_hash' => self::MANIFEST_HASH]], [], []);
    }
}

final class ContinuationCommandCaptureRepository implements CaptureRepository
{
    public int $created = 0;
    public function __construct(private CaptureRecord $record) {}
    public function findByIdempotencyKey(string $key): ?CaptureRecord { return null; }
    public function findById(string $captureId): ?CaptureRecord { return $captureId === $this->record->captureId ? $this->record : null; }
    public function create(CaptureRecord $record): CaptureRecord { $this->created++; return $record; }
    public function save(CaptureRecord $record): CaptureRecord { return $record; }
}
