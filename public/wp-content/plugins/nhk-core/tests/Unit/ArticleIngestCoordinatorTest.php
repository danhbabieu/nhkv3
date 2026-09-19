<?php
declare(strict_types=1);

namespace NHK\Tests\Unit;

use NHK\Core\Application\Article\ArticleIngestCoordinator;
use NHK\Core\Contracts\Article\ArticleOperationReceiptRepository;
use NHK\Core\Domain\Article\{ArticleIngestOutcome, ArticleOperationReceipt};
use NHK\Core\Domain\Article\EditorialPostState;
use NHK\Core\Application\Article\{ArticleIngestPreflight, SemanticProposalPlanner};
use NHK\Core\Application\Governance\GovernanceService;
use NHK\Core\Contracts\Article\ArticleApplyService;
use NHK\Core\Domain\Authority\{EntityTypeDefinition, EntityTypeRegistry};
use NHK\Core\Domain\Graph\{EndpointTypeRegistry, FakeEndpointResolver, PredicateRegistry};
use NHK\Core\Contracts\Article\EditorialStateReader;
use NHK\Core\Contracts\WordPress\EditorialPostStore;
use NHK\Tests\Support\InMemoryProposalRepository;
use NHK\Core\Governance\Exception\ProposalIdempotencyConflict;
use NHK\Core\Shared\Uuid\UuidCodec;
use PHPUnit\Framework\TestCase;

final class ArticleIngestCoordinatorTest extends TestCase
{
    public function test_bounded_update_requires_cas_then_mutates_and_verifies_native_readback(): void
    {
        $receipts = new class implements ArticleOperationReceiptRepository {
            public array $items = [];
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->items[$key] ?? null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
        };
        $store = new class implements EditorialPostStore {
            public EditorialPostState $state;
            public function __construct() { $this->state = new EditorialPostState(621, '1:621', 'post', 'publish', 'Human title', 'Human prose', '', '', 'https://example.test/', 0, 0, '', [1], 489); }
            public function read(int $postId): ?EditorialPostState { return $this->state; }
            public function createDraft(array $fields): EditorialPostState { return $this->state; }
            public function update(int $postId, array $fields): EditorialPostState {
                $old = $this->state;
                $this->state = new EditorialPostState($old->postId, $old->endpointKey, $old->postType, $old->status, (string) ($fields['post_title'] ?? $old->title), (string) ($fields['post_content'] ?? $old->content), (string) ($fields['post_excerpt'] ?? $old->excerpt), (string) ($fields['post_name'] ?? $old->slug), $old->permalink, $old->latestRevisionId + 1, $old->revisionCount + 1, $old->modifiedGmt, array_key_exists('category_ids', $fields) ? array_values(array_map('intval', (array) $fields['category_ids'])) : $old->categoryIds, array_key_exists('featured_media_id', $fields) ? ((int) $fields['featured_media_id'] ?: null) : $old->featuredAttachmentId);
                return $this->state;
            }
            public function publish(int $postId): EditorialPostState { return $this->state; }
            public function trash(int $postId): EditorialPostState { return $this->state; }
            public function restore(int $postId): EditorialPostState { return $this->state; }
        };
        $reader = new class($store) implements EditorialStateReader {
            public function __construct(private EditorialPostStore $store) {}
            public function read(int $postId): ?EditorialPostState { return $this->store->read($postId); }
        };
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:621']));
        $types = new EntityTypeRegistry();
        $proposals = new InMemoryProposalRepository();
        $coordinator = new ArticleIngestCoordinator($receipts, new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types), new SemanticProposalPlanner(), $reader, new GovernanceService($proposals), null, $proposals, null, new \NHK\Core\Application\Article\ArticleVerificationReader(), null, $store);
        $base = ['intent' => 'update', 'target_wp_post' => ['endpoint_type' => 'wp_post', 'endpoint_key' => '1:621'], 'semantic_bundle' => ['commands' => []], 'editorial_update' => ['fields' => ['post_name' => 'odo-36', 'category_ids' => [4], 'featured_media_id' => 0]]];
        $first = $coordinator->execute($base + ['idempotency_key' => 'article-cas-1']);
        self::assertSame('EDITORIAL_CAS_REQUIRED', $first->failure['code']);
        self::assertSame('update', $first->intent);
        $complete = $coordinator->execute($base + ['idempotency_key' => 'article-cas-2', 'expected_editorial_state' => ['state_token' => $first->wpStateToken]]);
        self::assertSame(ArticleIngestOutcome::COMPLETED, $complete->outcome);
        self::assertSame('odo-36', $store->state->slug);
        self::assertSame([4], $store->state->categoryIds);
        self::assertNull($store->state->featuredAttachmentId);
    }

    public function test_create_is_recorded_as_unsupported_without_editorial_side_effect(): void
    {
        $repository = new class implements ArticleOperationReceiptRepository {
            public ?ArticleOperationReceipt $receipt = null;
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->receipt?->idempotencyKey === $key ? $this->receipt : null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->receipt = $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->receipt = $receipt; }
        };
        $coordinator = new ArticleIngestCoordinator($repository);

        $receipt = $coordinator->execute(['idempotency_key' => 'create-1', 'intent' => 'create']);

        self::assertSame(ArticleIngestOutcome::UNSUPPORTED_OPERATION, $receipt->outcome);
        self::assertFalse($receipt->retryable);
    }

    public function test_same_key_with_different_payload_fails_closed(): void
    {
        $repository = new class implements ArticleOperationReceiptRepository {
            /** @var array<string,ArticleOperationReceipt> */ public array $items = [];
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->items[$key] ?? null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
        };
        $coordinator = new ArticleIngestCoordinator($repository);
        $coordinator->execute(['idempotency_key' => 'same-key', 'intent' => 'create', 'editorial' => ['title' => 'A']]);

        $conflict = $coordinator->execute(['idempotency_key' => 'same-key', 'intent' => 'create', 'editorial' => ['title' => 'B']]);
        self::assertSame(ArticleIngestOutcome::IDEMPOTENCY_CONFLICT, $conflict->outcome);
        self::assertSame('ARTICLE_IDEMPOTENCY_KEY_REUSED', $conflict->failure['code']);
    }

    public function test_reconcile_reads_post_and_submits_deterministic_semantic_proposal(): void
    {
        $receipts = new class implements ArticleOperationReceiptRepository {
            /** @var array<string,ArticleOperationReceipt> */ public array $items = [];
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->items[$key] ?? null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
        };
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $reader = new class implements EditorialStateReader {
            public function read(int $postId): ?EditorialPostState { return new EditorialPostState($postId, '1:' . $postId, 'post', 'publish', 'Title', 'Body', '', 'title', 'https://example.test/title/', 0, 0); }
        };
        $proposalRepository = new InMemoryProposalRepository();
        $coordinator = new ArticleIngestCoordinator(
            $receipts,
            new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types),
            new SemanticProposalPlanner(),
            $reader,
            new GovernanceService($proposalRepository),
            null,
            $proposalRepository,
        );

        $receipt = $coordinator->execute(['idempotency_key' => 'reconcile-1', 'intent' => 'reconcile', 'target_wp_post' => ['endpoint_key' => '1:55'], 'semantic_bundle' => ['commands' => [[
            'slot' => 'brand', 'operation' => 'update', 'entity_type' => 'brand', 'subject_id' => 'brand-id', 'target_uuid' => '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22', 'expected_revision' => 1, 'payload' => ['entity_payload' => []],
        ]]]]);

        self::assertSame(ArticleIngestOutcome::GOVERNANCE_PENDING, $receipt->outcome);
        self::assertSame('1:55', $receipt->wpEndpointKey);
        self::assertSame(1, count($receipt->proposalIds));
        self::assertSame('submitted', $receipt->proposalStates[$receipt->proposalIds[0]]);
        self::assertSame('submitted', $proposalRepository->find($receipt->proposalIds[0])?->state->value);
    }

    public function test_approved_reconcile_resumes_and_applies_each_child_once(): void
    {
        $receipts = new class implements ArticleOperationReceiptRepository {
            /** @var array<string,ArticleOperationReceipt> */ public array $items = [];
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->items[$key] ?? null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
        };
        $endpoints = new EndpointTypeRegistry();
        $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $types = new EntityTypeRegistry();
        $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $reader = new class implements EditorialStateReader {
            public function read(int $postId): ?EditorialPostState { return new EditorialPostState($postId, '1:' . $postId, 'post', 'publish', 'Title', 'Body', '', 'title', 'https://example.test/title/', 0, 0); }
        };
        $proposalRepository = new InMemoryProposalRepository();
        $governance = new GovernanceService($proposalRepository);
        $apply = new class implements ArticleApplyService {
            /** @var list<string> */ public array $ids = [];
            public function apply(string $proposalId): array { $this->ids[] = $proposalId; return ['proposal_id' => $proposalId]; }
        };
        $coordinator = new ArticleIngestCoordinator(
            $receipts,
            new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types),
            new SemanticProposalPlanner(), $reader, $governance, $apply, $proposalRepository,
            null, new \NHK\Core\Application\Article\ArticleVerificationReader(),
        );
        $input = ['idempotency_key' => 'resume-once', 'intent' => 'reconcile', 'target_wp_post' => ['endpoint_key' => '1:55'], 'semantic_bundle' => ['commands' => [[
            'slot' => 'brand', 'operation' => 'update', 'entity_type' => 'brand', 'subject_id' => 'brand-id', 'target_uuid' => '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22', 'expected_revision' => 1, 'payload' => ['entity_payload' => []],
        ]]]];
        $pending = $coordinator->execute($input);
        $proposal = $proposalRepository->find($pending->proposalIds[0]);
        self::assertNotNull($proposal);
        $governance->approve($proposal->id, $proposal->contentFingerprint, $proposal->dependencyFingerprint, '1');
        $complete = $coordinator->execute($input);
        $replay = $coordinator->execute($input);
        self::assertSame(ArticleIngestOutcome::COMPLETED, $complete->outcome);
        self::assertSame(ArticleIngestOutcome::COMPLETED, $replay->outcome);
        self::assertCount(1, $apply->ids);
    }

    public function test_reconcile_does_not_report_success_when_controlled_apply_is_unavailable(): void
    {
        $receipts = new class implements ArticleOperationReceiptRepository {
            /** @var array<string,ArticleOperationReceipt> */ public array $items = [];
            public function findByIdempotencyKey(string $key): ?ArticleOperationReceipt { return $this->items[$key] ?? null; }
            public function create(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
            public function save(ArticleOperationReceipt $receipt): ArticleOperationReceipt { return $this->items[$receipt->idempotencyKey] = $receipt; }
        };
        $endpoints = new EndpointTypeRegistry(); $endpoints->register('wp_post', new FakeEndpointResolver('wp_post', ['1:55']));
        $types = new EntityTypeRegistry(); $types->register(new EntityTypeDefinition('brand', 1, true, []));
        $reader = new class implements EditorialStateReader { public function read(int $postId): ?EditorialPostState { return new EditorialPostState(55, '1:55', 'post', 'publish', 'T', 'B', '', 't', 'https://example.test/t/', 0, 0); } };
        $proposals = new InMemoryProposalRepository();
        $coordinator = new ArticleIngestCoordinator($receipts, new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types), new SemanticProposalPlanner(), $reader, new GovernanceService($proposals), null, $proposals, null, new \NHK\Core\Application\Article\ArticleVerificationReader());
        $input = ['idempotency_key' => 'no-apply', 'intent' => 'reconcile', 'target_wp_post' => ['endpoint_key' => '1:55'], 'semantic_bundle' => ['commands' => [['slot' => 'brand', 'operation' => 'update', 'entity_type' => 'brand', 'subject_id' => 'b', 'target_uuid' => '018f7c48-6d87-7a1d-8c9e-3b8c4c8d1f22', 'expected_revision' => 1, 'payload' => []]]]];
        $pending = $coordinator->execute($input);
        $proposal = $proposals->find($pending->proposalIds[0]);
        self::assertNotNull($proposal);
        $coordinatorWithoutApply = new ArticleIngestCoordinator($receipts, new ArticleIngestPreflight($endpoints, new PredicateRegistry(), $types), new SemanticProposalPlanner(), $reader, new GovernanceService($proposals), null, $proposals, null, new \NHK\Core\Application\Article\ArticleVerificationReader());
        self::assertSame(ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE, $coordinatorWithoutApply->execute($input)->outcome);
    }

    public function test_resume_recovery_accepts_only_receipt_bound_current_token(): void
    {
        $currentToken = str_repeat('c', 64);
        $receipt = new ArticleOperationReceipt(
            operationId: UuidCodec::newV7(),
            idempotencyKey: 'recovery-boundary',
            requestFingerprint: str_repeat('a', 64),
            intent: 'reconcile',
            wpEndpointKey: '1:1',
            wpPostId: 1,
            stage: 'media',
            outcome: ArticleIngestOutcome::DEPENDENCY_UNAVAILABLE,
            retryable: true,
            diagnostics: ['media' => ['editorialStateToken' => $currentToken]],
        );
        $coordinator = new ArticleIngestCoordinator($this->createMock(ArticleOperationReceiptRepository::class));
        $method = new \ReflectionMethod($coordinator, 'isExpectedSelfMutation');

        self::assertTrue($method->invoke($coordinator, $receipt, $currentToken));
        self::assertFalse($method->invoke($coordinator, $receipt, str_repeat('d', 64)));
    }
}
