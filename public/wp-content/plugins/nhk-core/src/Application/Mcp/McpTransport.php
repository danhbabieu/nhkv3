<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Video\VideoIntakeService;
use NHK\Core\Application\Video\VideoSourceRefreshCommand;
use NHK\Core\Contracts\Media\WordPressMediaAttachmentIngestor;
use NHK\Core\Application\Media\{ImageIngestEntrypoint, MediaBatchUploadService, MediaBindingService};
use NHK\Core\Application\WordPress\{CategoryGateway, EditorialDraftGateway};
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\Knowledge\KnowledgeRepairPreviewService;
use NHK\Core\Application\PublicIdentity\PublicUrlMaintenanceService;
use NHK\Core\Application\Capture\{AuthorityCaptureService, EditorialCaptureContinuationService, EditorialCaptureCoordinator, PlanReapprovalRequired};
use NHK\Core\Application\Graph\RelationshipOwnerContract;
use NHK\Core\Application\Runtime\{SemanticWritePolicyResolver, SemanticWritePolicyViolation};
use NHK\Core\Domain\Knowledge\DependencyValidationException;
use NHK\Core\Infrastructure\Mcp\ChatGptMcpGatewayException;

final class McpTransport
{
    public const MODERN_VERSION = '2026-07-28';
    public const LEGACY_VERSION = '2025-11-25';

    /** @param callable(string):bool|null $can */
    /** @param callable(string):bool|null $originAllowed */
    public function __construct(
        private McpReadHandler $read,
        private McpGovernanceHandler $governance,
        private $can = null,
        private $originAllowed = null,
        private ?McpArticleIngestHandler $article = null,
        private ?VideoIntakeService $videoIntake = null,
        private ?WordPressMediaAttachmentIngestor $wordpressAttachments = null,
        private ?CategoryGateway $categories = null,
        private ?EditorialDraftGateway $drafts = null,
        private ?CanonicalDependencyValidator $dependencies = null,
        private ?PublicUrlMaintenanceService $publicUrls = null,
        private ?MediaBatchUploadService $mediaBatchUpload = null,
        private ?McpDocumentationRegistry $documentation = null,
        private ?EditorialCaptureCoordinator $capture = null,
        private ?EditorialCaptureContinuationService $captureContinuation = null,
        private ?AuthorityCaptureService $authorityCapture = null,
        /** @var callable():bool|null */
        private $runtimeWriteReady = null,
        private ?ImageIngestEntrypoint $imageIngest = null,
        private ?SemanticWritePolicyResolver $semanticWritePolicy = null,
        private ?MediaBindingService $mediaBinding = null,
        private ?VideoSourceRefreshCommand $videoSourceRefresh = null,
        private ?KnowledgeRepairPreviewService $knowledgeRepairPreview = null,
    ) {}

    /** @return array{status:int,body:?array} */
    public function dispatch(array $request, array $headers = [], array $files = []): array
    {
        $id = array_key_exists('id', $request) ? $request['id'] : null;
        if (($request['jsonrpc'] ?? null) !== '2.0' || !is_string($request['method'] ?? null)) return $this->error($id, -32600, 'Invalid Request.', 400);
        $method = $request['method'];
        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        $modern = $this->isModern($request, $params, $headers);

        $origin = $this->header($headers, 'Origin');
        if ($origin !== '' && $this->originAllowed && !(bool) ($this->originAllowed)($origin)) return $this->error(null, -32003, 'Origin is not allowed.', 403);
        if ($modern) {
            if (!$this->acceptsStreamableHttp($headers)) return $this->error($id, -32020, 'Accept header must include application/json and text/event-stream.', 400);
            $version = $this->header($headers, 'MCP-Protocol-Version');
            $metadataVersion = (string) ($this->meta($request, $params)['io.modelcontextprotocol/protocolVersion'] ?? '');
            $bodyVersion = (string) ($params['protocolVersion'] ?? '');
            $bodyVersion = $bodyVersion !== '' ? $bodyVersion : $metadataVersion;
            if ($version !== '' && $version !== self::MODERN_VERSION) return $this->error($id, -32022, 'Unsupported protocol version.', 400, ['supported' => [self::MODERN_VERSION, self::LEGACY_VERSION], 'requested' => $version]);
            if ($bodyVersion !== '' && $bodyVersion !== self::MODERN_VERSION) return $this->error($id, -32022, 'Unsupported protocol version.', 400, ['supported' => [self::MODERN_VERSION, self::LEGACY_VERSION], 'requested' => $bodyVersion]);
            if ($version !== '' && $bodyVersion !== '' && $bodyVersion !== $version) return $this->error($id, -32020, 'Header mismatch: protocol version.', 400);
            $declaredMethod = $this->header($headers, 'Mcp-Method');
            if ($declaredMethod !== '' && $declaredMethod !== $method) return $this->error($id, -32020, 'Header mismatch: Mcp-Method.', 400);
            $declaredName = $this->header($headers, 'Mcp-Name');
            if ($declaredName !== '' && $method === 'tools/call' && $declaredName !== (string) ($params['name'] ?? '')) return $this->error($id, -32020, 'Header mismatch: Mcp-Name.', 400);
        }

        if (!array_key_exists('id', $request) && str_starts_with($method, 'notifications/')) return ['status' => 202, 'body' => null];
        try {
            return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $this->handle($method, $params, $modern, $files)]];
        } catch (McpMethodNotFound $error) {
            return $this->error($id, -32601, $error->getMessage(), 404);
        } catch (McpPermissionDenied $error) {
            return $this->error($id, -32003, 'Capability required: ' . $error->getMessage() . '.', 403);
        } catch (SingleEntryPointViolation $error) {
            return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'isError' => true,
                'structuredContent' => ['error' => $error->toArray()],
                'content' => [['type' => 'text', 'text' => $error->reasonCode . ': USE_CANONICAL_CAPTURE_FLOW']],
            ]]];
        } catch (SemanticWritePolicyViolation $error) {
            return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'isError' => true,
                'structuredContent' => ['error' => $error->toArray()],
                'content' => [['type' => 'text', 'text' => $error->reasonCode]],
            ]]];
        } catch (McpDocumentationException $error) {
            return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'isError' => true,
                'structuredContent' => ['error' => $error->toArray()],
                'content' => [['type' => 'text', 'text' => $error->reasonCode]],
            ]]];
        } catch (PlanReapprovalRequired $error) {
            return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => [
                'isError' => true,
                'structuredContent' => ['error' => ['code' => 'PLAN_REAPPROVAL_REQUIRED', 'reapproval' => $error->packet]],
                'content' => [['type' => 'text', 'text' => 'PLAN_REAPPROVAL_REQUIRED']],
            ]]];
        } catch (\InvalidArgumentException $error) {
            if (in_array($error->getMessage(), ['PLAN_REAPPROVAL_REQUIRED', 'APPROVED_CANDIDATE_UNKNOWN', 'APPROVED_DEPENDENCY_MISSING', 'AUTHORITY_PURPOSE_REQUIRED', 'AUTHORITY_PURPOSE_CONFLICT', 'AUTHORITY_APPROVAL_PACKET_REQUIRED', 'AUTHORITY_APPROVAL_PACKET_INVALID'], true)) return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['isError' => true, 'structuredContent' => ['error' => ['code' => $error->getMessage()]], 'content' => [['type' => 'text', 'text' => $error->getMessage()]]]]];
            return $this->error($id, -32602, $error->getMessage(), 400);
        } catch (DependencyValidationException $error) {
            return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['isError' => true, 'structuredContent' => ['error' => $error->toStructuredError()], 'content' => [['type' => 'text', 'text' => $error->getMessage()]]]]];
        } catch (\Throwable $error) {
            return ['status' => 200, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['isError' => true, 'content' => [['type' => 'text', 'text' => $error->getMessage()]]]]];
        }
    }

    private function handle(string $method, array $params, bool $modern, array $files = []): array
    {
        return match ($method) {
            'server/discover' => ['protocolVersions' => [self::MODERN_VERSION, self::LEGACY_VERSION], 'capabilities' => ['tools' => new \stdClass(), 'resources' => new \stdClass()], 'serverInfo' => ['name' => 'nhk-v3', 'version' => '3.0.0'], 'runtime_identity' => $this->runtimeIdentity()],
            'initialize' => ['protocolVersion' => $modern ? self::MODERN_VERSION : self::LEGACY_VERSION, 'capabilities' => ['tools' => new \stdClass(), 'resources' => new \stdClass()], 'serverInfo' => ['name' => 'nhk-v3', 'version' => '3.0.0'], 'runtime_identity' => $this->runtimeIdentity()],
            'tools/list' => ['tools' => array_map(static function (array $tool): array {
                $export = ['name' => $tool['name'], 'description' => $tool['description'], 'inputSchema' => $tool['inputSchema']];
                if (is_array($tool['connectorMeta'] ?? null) && $tool['connectorMeta'] !== []) $export['_meta'] = $tool['connectorMeta'];
                return $export;
            }, McpToolCatalog::tools())],
            'resources/list' => McpAppsResourceRegistry::list(),
            'resources/read' => McpAppsResourceRegistry::read((string) ($params['uri'] ?? '')),
            'tools/call' => $this->callTool($params, $files),
            default => throw new McpMethodNotFound($method),
        };
    }

    private function callTool(array $params, array $files = []): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if (in_array($name, ['nhk.media.ingest', 'nhk.media.upload-batch', 'nhk.capture.ingest'], true)) $arguments = $this->fileArgumentMetadata($arguments, $files);
        $definition = null;
        foreach (McpToolCatalog::tools() as $tool) if ($tool['name'] === $name) { $definition = $tool; break; }
        if ($definition === null) throw new McpMethodNotFound('tools/call:' . $name);
        $dispatch = $definition['dispatch'] ?? null;
        if (!is_string($dispatch) || $dispatch === '') throw new McpMethodNotFound('tools/call:' . $name);
        SingleEntryPointPolicy::guard($name, $this->can);
        $capability = match ($name) {
            'nhk.documentation.bootstrap', 'nhk.documentation.get', 'nhk.documentation.list', 'nhk.docs.bootstrap', 'nhk.docs.get' => 'read',
            'nhk.article.preflight', 'nhk.relationship.registry', 'nhk.relationship.list', 'nhk.relationship.get', 'nhk.relationship.preview' => 'read',
            'nhk.article.ingest' => 'nhk_ingest_articles',
            'nhk.capture.ingest' => 'nhk_ingest_articles',
            'nhk.category.create', 'nhk.category.update', 'nhk.category.assign', 'nhk.category.unassign', 'nhk.category.delete', 'nhk.article.draft.create', 'nhk.article.draft.update', 'nhk.article.publish', 'nhk.article.publish.review', 'nhk.article.publish.approve', 'nhk.article.trash', 'nhk.article.restore' => 'nhk_ingest_articles',
            'nhk.proposal.create' => 'nhk_create_proposals',
            'nhk.media.ingest', 'nhk.media.update', 'nhk.media.bind', 'nhk.media.usage' => 'nhk_create_proposals',
            'nhk.media.upload-batch' => 'upload_files',
            'nhk.media.widget-upload' => 'upload_files',
            'nhk.video.ingest' => 'nhk_create_proposals',
            'nhk.video.source.refresh' => 'nhk_create_proposals',
            'nhk.knowledge.ingest', 'nhk.source.ingest', 'nhk.evidence.ingest' => 'nhk_create_proposals',
            'nhk.proposal.submit' => 'nhk_submit_proposals',
            'nhk.proposal.review' => 'nhk_view_governance',
            'nhk.proposal.approve', 'nhk.proposal.reject' => 'nhk_approve_proposals',
            'nhk.proposal.eligibility' => 'nhk_view_governance',
            'nhk.proposal.apply' => 'nhk_apply_proposals',
            'nhk.relation.backfill.apply' => 'nhk_apply_proposals',
            'nhk.public-url.audit', 'nhk.public-url.reproject' => 'nhk_manage_public_urls',
            default => null,
        };
        if ($capability !== null && (!$this->can || !(bool) ($this->can)($capability))) throw new McpPermissionDenied($capability);
        if ($definition['kind'] === 'mutation' && $this->can !== null && !(bool) ($this->can)('read')) throw new McpPermissionDenied('read');
        $this->validateArguments($definition['inputSchema'], $arguments);
        if ($name === 'nhk.relationship.preview') $arguments = RelationshipOwnerContract::normalize($arguments);
        if ($name === 'nhk.capture.ingest') {
            $arguments = RelationshipOwnerContract::normalizeCapture($arguments);
            RelationshipOwnerContract::assertCaptureOperations($arguments);
            if (($arguments['dry_run'] ?? false) !== true) $arguments = RelationshipOwnerContract::routeMediaCompatibility($arguments);
        }
        $result = match ($dispatch) {
            'nhk.documentation.bootstrap', 'nhk.docs.bootstrap' => ($this->documentation ?? new McpDocumentationRegistry())->bootstrap(),
            'nhk.documentation.get' => ($this->documentation ?? new McpDocumentationRegistry())->get((string) ($arguments['path'] ?? ''), isset($arguments['start_line']) ? (int) $arguments['start_line'] : null, isset($arguments['line_count']) ? (int) $arguments['line_count'] : null),
            'nhk.documentation.list' => ($this->documentation ?? new McpDocumentationRegistry())->list(isset($arguments['status']) ? (string) $arguments['status'] : null, isset($arguments['domain']) ? (string) $arguments['domain'] : null, isset($arguments['path_prefix']) ? (string) $arguments['path_prefix'] : null),
            'nhk.docs.get' => ($this->documentation ?? new McpDocumentationRegistry())->get((string) ($arguments['document_key'] ?? '')),
            'nhk.public-url.audit' => $this->publicUrls?->audit(isset($arguments['owner_id']) ? (string) $arguments['owner_id'] : null) ?? throw new \RuntimeException('PUBLIC_URL_MAINTENANCE_UNAVAILABLE'),
            'nhk.public-url.reproject' => $this->publicUrls?->reproject((string) ($arguments['idempotency_key'] ?? ''), (bool) ($arguments['pre_public_confirmed'] ?? false), (string) ($arguments['owner_id'] ?? '')) ?? throw new \RuntimeException('PUBLIC_URL_MAINTENANCE_UNAVAILABLE'),
            'nhk.search' => $this->read->search((string) ($arguments['q'] ?? ''), (int) ($arguments['page'] ?? 1), (int) ($arguments['per_page'] ?? 20)),
            'nhk.canonical.inventory' => $this->read->canonicalInventory((array) ($arguments['filters'] ?? []), (int) ($arguments['limit'] ?? 50), isset($arguments['after']) ? (string) $arguments['after'] : null),
            'nhk.graph.inventory' => $this->read->graphInventory((array) ($arguments['filters'] ?? []), (int) ($arguments['limit'] ?? 50), isset($arguments['after']) ? (string) $arguments['after'] : null),
            'nhk.relationship.registry' => $this->read->relationshipRegistry(),
            'nhk.relationship.list' => $this->read->relationshipList((array) ($arguments['filters'] ?? []), (int) ($arguments['limit'] ?? 50), isset($arguments['after']) ? (string) $arguments['after'] : null),
            'nhk.relationship.get' => $this->read->relationshipGet((string) ($arguments['id'] ?? ''), isset($arguments['relationship_kind']) ? (string) $arguments['relationship_kind'] : null, (array) ($arguments['context'] ?? [])),
            'nhk.relationship.preview' => $this->read->relationshipPreview($arguments),
            'nhk.relation.backfill.dry_run' => $this->read->relationBackfillDryRun((array) ($arguments['records'] ?? [])),
            'nhk.relation.backfill.apply' => $this->governance->relationBatchApply((array) ($arguments['candidates'] ?? []), (bool) ($arguments['approval_confirmed'] ?? false)),
            'nhk.semantic.resolve' => $this->read->semanticResolve((array) ($arguments['context'] ?? [])),
            'nhk.capture.get' => $this->read->captureGet((string) ($arguments['id'] ?? '')),
            'nhk.entity.neighborhood' => $this->read->entityNeighborhood((string) ($arguments['type'] ?? ''), (string) ($arguments['id'] ?? ''), (string) ($arguments['profile'] ?? ''), (int) ($arguments['max_hops'] ?? 2), (int) ($arguments['limit'] ?? 50)),
            'nhk.article.preflight' => $this->article?->preflight($arguments) ?? throw new \RuntimeException('ARTICLE_INGEST_HANDLER_UNAVAILABLE'),
            'nhk.article.ingest' => $this->article?->ingest($arguments) ?? throw new \RuntimeException('ARTICLE_INGEST_HANDLER_UNAVAILABLE'),
            'nhk.capture.ingest' => $this->captureIngest($arguments, $files),
            'nhk.category.resolve' => $this->categories?->resolve((array) ($arguments['selector'] ?? [])) ?? throw new \RuntimeException('CATEGORY_GATEWAY_UNAVAILABLE'),
            'nhk.category.create' => $this->categories?->create((string) ($arguments['name'] ?? ''), (string) ($arguments['slug'] ?? ''), (int) ($arguments['parent'] ?? 0)) ?? throw new \RuntimeException('CATEGORY_GATEWAY_UNAVAILABLE'),
            'nhk.category.update' => $this->categories?->update((int) ($arguments['id'] ?? 0), (array) ($arguments['changes'] ?? []), isset($arguments['expected_fingerprint']) ? (string) $arguments['expected_fingerprint'] : null) ?? throw new \RuntimeException('CATEGORY_GATEWAY_UNAVAILABLE'),
            'nhk.category.assign' => $this->categories?->assign((int) ($arguments['post_id'] ?? 0), (int) ($arguments['category_id'] ?? 0)) ?? throw new \RuntimeException('CATEGORY_GATEWAY_UNAVAILABLE'),
            'nhk.category.unassign' => $this->categories?->unassign((int) ($arguments['post_id'] ?? 0), (int) ($arguments['category_id'] ?? 0)) ?? throw new \RuntimeException('CATEGORY_GATEWAY_UNAVAILABLE'),
            'nhk.category.delete' => $this->categories?->delete((int) ($arguments['id'] ?? 0), (bool) ($arguments['allow_reassign'] ?? false)) ?? throw new \RuntimeException('CATEGORY_GATEWAY_UNAVAILABLE'),
            'nhk.article.draft.create' => $this->drafts?->create($arguments) ?? throw new \RuntimeException('EDITORIAL_DRAFT_GATEWAY_UNAVAILABLE'),
            'nhk.article.draft.update' => $this->drafts?->update((int) ($arguments['post_id'] ?? 0), (array) ($arguments['fields'] ?? []), (string) ($arguments['expected_state_token'] ?? '')) ?? throw new \RuntimeException('EDITORIAL_DRAFT_GATEWAY_UNAVAILABLE'),
            'nhk.article.publish' => $this->drafts?->publish((int) ($arguments['post_id'] ?? 0), (string) ($arguments['expected_state_token'] ?? ''), (array) ($arguments['evidence'] ?? []), (string) ($arguments['idempotency_key'] ?? '')) ?? throw new \RuntimeException('EDITORIAL_DRAFT_GATEWAY_UNAVAILABLE'),
            'nhk.article.publish.review' => $this->drafts?->reviewPublication((int) ($arguments['post_id'] ?? 0), (string) ($arguments['expected_state_token'] ?? ''), (array) ($arguments['evidence'] ?? []), (string) ($arguments['idempotency_key'] ?? '')) ?? throw new \RuntimeException('EDITORIAL_DRAFT_GATEWAY_UNAVAILABLE'),
            'nhk.article.publish.approve' => $this->drafts?->approvePublication((int) ($arguments['post_id'] ?? 0), (string) ($arguments['expected_state_token'] ?? ''), (array) ($arguments['evidence'] ?? []), (string) ($arguments['idempotency_key'] ?? ''), (string) ($arguments['decision_id'] ?? ''), (string) ($arguments['affirmation'] ?? ''), function_exists('get_current_user_id') ? (string) get_current_user_id() : '0', '') ?? throw new \RuntimeException('EDITORIAL_DRAFT_GATEWAY_UNAVAILABLE'),
            'nhk.article.trash' => $this->drafts?->trash((int) ($arguments['post_id'] ?? 0), (string) ($arguments['expected_state_token'] ?? ''), (string) ($arguments['idempotency_key'] ?? '')) ?? throw new \RuntimeException('EDITORIAL_DRAFT_GATEWAY_UNAVAILABLE'),
            'nhk.article.restore' => $this->drafts?->restore((int) ($arguments['post_id'] ?? 0), (string) ($arguments['expected_state_token'] ?? ''), (string) ($arguments['idempotency_key'] ?? '')) ?? throw new \RuntimeException('EDITORIAL_DRAFT_GATEWAY_UNAVAILABLE'),
            'nhk.entity.get' => $this->read->entityGet((string) ($arguments['type'] ?? ''), (string) ($arguments['id'] ?? '')),
            'nhk.media.get' => $this->read->mediaGet((string) ($arguments['id'] ?? '')),
            'nhk.media.update' => $this->mediaUpdate($arguments),
            'nhk.media.binding.get' => $this->mediaBinding?->get((string) ($arguments['operation_id'] ?? ''), (string) ($arguments['idempotency_key'] ?? '')) ?? throw new \RuntimeException('MEDIA_BINDING_SERVICE_UNAVAILABLE'),
            'nhk.media.bind' => $this->mediaBind($arguments),
            'nhk.media.usage' => $this->mediaUsage($arguments),
            'nhk.media.ingest' => $this->mediaIngest($arguments, $files),
            'nhk.media.upload-batch' => $this->batchUpload($arguments, $files),
            'nhk.media.widget-upload' => $this->widgetUpload($arguments),
            'nhk.media.upload-widget.open' => ['resourceUri' => McpAppsResourceRegistry::IMAGE_UPLOAD_URI],
            'nhk.media.attachment.get' => $this->read->mediaAttachmentGet((int) ($arguments['attachment_id'] ?? 0)),
            'nhk.video.ingest' => $this->videoIngest($arguments),
            'nhk.video.source.refresh' => $this->videoSourceRefresh?->prepare((string) ($arguments['video_id'] ?? ''), (int) ($arguments['expected_revision'] ?? 0), array_key_exists('expected_source_revision', $arguments) ? (int) $arguments['expected_source_revision'] : null, (string) ($arguments['idempotency_key'] ?? '')) ?? throw new \RuntimeException('VIDEO_SOURCE_REFRESH_UNAVAILABLE'),
            'nhk.video.get' => $this->read->videoGet((string) ($arguments['id'] ?? '')),
            'nhk.knowledge.get' => $this->read->knowledgeGet((string) ($arguments['id'] ?? '')),
            'nhk.source.get' => $this->read->sourceGet((string) ($arguments['id'] ?? '')),
            'nhk.evidence.get' => $this->read->evidenceGet((string) ($arguments['id'] ?? '')),
            'nhk.knowledge.ingest' => $this->knowledgeIngest($arguments),
            'nhk.source.ingest' => $this->sourceIngest($arguments),
            'nhk.evidence.ingest' => $this->evidenceIngest($arguments),
            'nhk.proposal.create' => $this->proposal($this->governance->createFromArguments($arguments)),
            'nhk.proposal.submit' => $this->proposal($this->governance->submit($this->required($arguments, 'id'))),
            'nhk.proposal.review' => $this->governance->review($this->required($arguments, 'id')),
            'nhk.proposal.approve' => $this->proposal($this->governance->approve($this->required($arguments, 'id'), $this->required($arguments, 'content_fingerprint'), $this->required($arguments, 'dependency_fingerprint'), function_exists('get_current_user_id') ? (string) get_current_user_id() : '0')),
            'nhk.proposal.reject' => $this->proposal($this->governance->reject($this->required($arguments, 'id'), function_exists('get_current_user_id') ? (string) get_current_user_id() : '0')),
            'nhk.proposal.eligibility' => $this->governance->eligibility($this->required($arguments, 'id')),
            'nhk.proposal.apply' => $this->governance->apply($this->required($arguments, 'id')),
            'nhk.relation.backfill.apply' => $this->governance->relationBatchApply((array) ($arguments['candidates'] ?? []), (bool) ($arguments['approval_confirmed'] ?? false)),
        };
        $text = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $result, 'isError' => false];
    }

    private function batchUpload(array $arguments, array $files): array
    {
        if ($this->imageIngest !== null) {
            $provided = $files !== [] ? $files : ($arguments['files'] ?? null);
            return $this->imageIngest->ingest(
                (string) ($arguments['idempotency_key'] ?? ''),
                is_array($arguments['metadata'] ?? null) ? $arguments['metadata'] : [],
                $provided,
                is_array($arguments['items'] ?? null) ? $arguments['items'] : [],
                $files !== [],
            );
        }
        if ($this->mediaBatchUpload === null) throw new \RuntimeException('MEDIA_BATCH_UPLOAD_UNAVAILABLE');
        return $this->mediaBatchUpload->upload(
            (string) ($arguments['idempotency_key'] ?? ''),
            is_array($arguments['metadata'] ?? null) ? $arguments['metadata'] : [],
            $files,
            is_array($arguments['items'] ?? null) ? $arguments['items'] : [],
        );
    }

    /** @return array<string,mixed> */
    private function widgetUpload(array $arguments): array
    {
        if ($this->imageIngest === null) throw new \RuntimeException('IMAGE_INGEST_UNAVAILABLE');
        $references = is_array($arguments['files'] ?? null) ? array_values($arguments['files']) : [];
        $metadata = is_array($arguments['metadata'] ?? null) ? $arguments['metadata'] : [];
        $description = trim((string) ($metadata['description'] ?? ''));
        $itemPackets = is_array($arguments['items'] ?? null) ? array_values($arguments['items']) : [];
        $itemsByOrdinal = [];
        $itemsByFileId = [];
        foreach ($itemPackets as $packetIndex => $packet) {
            if (!is_array($packet)) throw new \InvalidArgumentException('Widget upload items must be structured objects.');
            $ordinal = $packet['ordinal'] ?? ($packet['sort_order'] ?? $packetIndex);
            if (!is_int($ordinal) || $ordinal < 0 || $ordinal >= count($references) || array_key_exists($ordinal, $itemsByOrdinal)) throw new \InvalidArgumentException('Widget upload item ordinal must map exactly once to files[].');
            $itemsByOrdinal[$ordinal] = $packet;
            $clientFileId = trim((string) ($packet['client_file_id'] ?? ''));
            if ($clientFileId !== '') $itemsByFileId[$clientFileId] = $packet;
        }
        if ($itemPackets !== [] && count($itemsByOrdinal) !== count($references)) {
            throw new \InvalidArgumentException('Widget upload items must map every files[] ordinal.');
        }
        if ($description === '' && count($references) === 1 && !isset($itemsByOrdinal[0]['media']['title'])) {
            throw new \InvalidArgumentException('TRUSTWORTHY_FILENAME_CONTEXT_REQUIRED');
        }
        $items = [];
        foreach ($references as $index => $reference) {
            if (!is_array($reference)) throw new \InvalidArgumentException('Widget upload references must be structured file objects.');
            if (array_key_exists('ordinal', $reference) && (!is_int($reference['ordinal']) || $reference['ordinal'] !== $index)) throw new \InvalidArgumentException('Widget upload file ordinal must match files[] order.');
            $fileId = (string) ($reference['file_id'] ?? '');
            $itemPacket = $itemsByFileId[$fileId] ?? ($itemsByOrdinal[$index] ?? []);
            $items[] = [
                'client_file_id' => $fileId,
                'filename' => (string) ($reference['file_name'] ?? ''),
                'sort_order' => $index,
                'ordinal' => $index,
                'media' => is_array($itemPacket['media'] ?? null) ? $itemPacket['media'] : (is_array($reference['media'] ?? null) ? $reference['media'] : []),
            ];
        }
        try {
            $manifest = $this->imageIngest->ingest(
                (string) ($arguments['idempotency_key'] ?? ''),
                ['source' => 'chatgpt_widget', 'description' => $description],
                $references,
                $items,
                false,
            );
        } catch (ChatGptMcpGatewayException $error) {
            return $this->widgetFailureManifest(count($references), $error);
        }
        $itemsByFileId = [];
        $itemsByOrdinal = [];
        foreach (array_values(array_filter((array) ($manifest['items'] ?? []), 'is_array')) as $index => $item) {
            $clientFileId = (string) ($item['client_file_id'] ?? '');
            if ($clientFileId !== '') $itemsByFileId[$clientFileId] = $item;
            $itemsByOrdinal[(int) ($item['ordinal'] ?? $index)] = $item;
        }
        $errorsByFileId = [];
        $errorsByOrdinal = [];
        foreach (array_values(array_filter((array) ($manifest['errors'] ?? []), 'is_array')) as $index => $error) {
            $clientFileId = (string) ($error['client_file_id'] ?? '');
            if ($clientFileId !== '') $errorsByFileId[$clientFileId] = $error;
            $errorsByOrdinal[(int) ($error['ordinal'] ?? $index)] = $error;
        }
        $uploads = [];
        $safeItems = [];
        $safeErrors = [];
        foreach ($references as $ordinal => $reference) {
            if (!is_array($reference)) continue;
            $fileId = (string) ($reference['file_id'] ?? '');
            $item = $itemsByFileId[$fileId] ?? $itemsByOrdinal[(int) $ordinal] ?? null;
            if (!is_array($item)) {
                $error = $errorsByFileId[$fileId] ?? $errorsByOrdinal[(int) $ordinal] ?? ['code' => 'UPLOAD_RESULT_ITEM_MISSING'];
                $code = self::safeWidgetErrorCode((string) ($error['code'] ?? 'UPLOAD_FAILED'));
                $safeItems[] = [
                    'ordinal' => (int) $ordinal,
                    'status' => 'error',
                    'original_filename' => (string) ($reference['file_name'] ?? ''),
                    'error_code' => $code,
                    'error' => [
                        'code' => $code,
                        'stage' => self::safeWidgetStage((string) ($error['stage'] ?? 'ingest')),
                        'message' => 'The image could not be ingested.',
                    ],
                ];
                $safeErrors[] = ['ordinal' => (int) $ordinal, 'code' => $code, 'stage' => self::safeWidgetStage((string) ($error['stage'] ?? 'ingest'))];
                continue;
            }
            $itemStatus = strtolower(trim((string) ($item['status'] ?? 'success')));
            if (in_array($itemStatus, ['error', 'failed', 'failure'], true) || isset($item['error'])) {
                $error = is_array($item['error'] ?? null) ? $item['error'] : $item;
                $code = self::safeWidgetErrorCode((string) ($error['code'] ?? $error['error_code'] ?? 'UPLOAD_FAILED'));
                $stage = self::safeWidgetStage((string) ($error['stage'] ?? 'ingest'));
                $safeItems[] = [
                    'ordinal' => (int) $ordinal,
                    'status' => 'error',
                    'original_filename' => (string) ($item['original_filename'] ?? ($reference['file_name'] ?? '')),
                    'error_code' => $code,
                    'error' => ['code' => $code, 'stage' => $stage, 'message' => 'The image could not be ingested.'],
                ];
                $safeErrors[] = ['ordinal' => (int) $ordinal, 'code' => $code, 'stage' => $stage];
                continue;
            }
            $upload = [
                'ordinal' => (int) $ordinal,
                'status' => 'success',
                'attachment_id' => (int) ($item['attachment_id'] ?? 0),
                'media_id' => (string) ($item['media_id'] ?? ''),
                'public_filename' => (string) ($item['filename'] ?? ''),
                'original_filename' => (string) ($item['original_filename'] ?? ($reference['file_name'] ?? '')),
                'mime' => (string) ($item['mime_type'] ?? ''),
                'width' => (int) ($item['width'] ?? 0),
                'height' => (int) ($item['height'] ?? 0),
                'filesize' => (int) ($item['byte_size'] ?? 0),
                'canonical_url' => self::modelVisibleWidgetCanonicalUrl((string) ($item['source_url'] ?? '')),
                'attachment_readback_status' => (string) ($item['attachment_readback_status'] ?? ''),
            ];
            $mediaContext = is_array($item['media_context'] ?? null) ? $item['media_context'] : [];
            if ($mediaContext !== []) $upload['metadata'] = array_intersect_key($mediaContext, array_flip(['title', 'alt_text', 'caption', 'description', 'seo_slug']));
            $modelVisibleFileId = self::modelVisibleWidgetFileId((string) ($item['client_file_id'] ?? $fileId));
            if ($modelVisibleFileId !== null) $upload['file_id'] = $modelVisibleFileId;
            $uploads[] = $upload;
            $safeItems[] = $upload;
        }
        $status = $uploads === [] ? 'error' : ($safeErrors === [] ? 'success' : 'partial_success');
        return [
            'status' => $status,
            'batch_id' => isset($manifest['batch_id']) ? (string) $manifest['batch_id'] : null,
            'user_context' => $description,
            'ordered_media_ids' => array_values(array_map(static fn (array $item): string => (string) ($item['media_id'] ?? ''), array_filter($uploads, static fn (array $item): bool => trim((string) ($item['media_id'] ?? '')) !== ''))),
            'media_commit_status' => $status === 'success' ? 'COMPLETE' : ($status === 'partial_success' ? 'PARTIAL' : 'FAILED'),
            'enrichment_status' => 'NOT_RUN',
            'requested_count' => count($references),
            'success_count' => count($uploads),
            'failure_count' => count($safeItems) - count($uploads),
            'items' => $safeItems,
            'uploads' => $uploads,
            'errors' => $safeErrors,
        ];
    }

    private static function safeWidgetStage(string $stage): string
    {
        $stage = strtolower(trim($stage));
        return preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $stage) === 1 ? $stage : 'ingest';
    }

    private static function safeWidgetErrorCode(string $code): string
    {
        $code = strtoupper(trim($code));
        return preg_match('/^[A-Z][A-Z0-9_]{2,}$/', $code) === 1 ? $code : 'UPLOAD_FAILED';
    }

    /** @return array<string,mixed> */
    private function widgetFailureManifest(int $requested, ChatGptMcpGatewayException $error): array
    {
        $code = self::safeWidgetErrorCode($error->safeReasonCode());
        $diagnostics = $error->diagnostics();
        $safeError = [
            'code' => $code,
            'stage' => self::safeWidgetStage((string) ($diagnostics['stage'] ?? 'materialization')),
            'message' => $error->safeMessage(),
        ];
        foreach (['correlation_id', 'host', 'http_status', 'redirect_count', 'resolved_public_address_count', 'content_bytes_received', 'decoder_stage'] as $key) {
            $value = $key === 'host' ? $error->host() : ($diagnostics[$key] ?? null);
            if ($value !== null && $value !== '') $safeError[$key] = $value;
        }
        $items = [];
        for ($ordinal = 0; $ordinal < $requested; $ordinal++) {
            $items[] = ['ordinal' => $ordinal, 'status' => 'error', 'error' => $safeError, 'error_code' => $code];
        }
        return [
            'status' => 'error',
            'requested_count' => $requested,
            'success_count' => 0,
            'failure_count' => $requested,
            'items' => $items,
            'errors' => array_map(static fn (array $item): array => ['ordinal' => $item['ordinal'], 'code' => $code, 'stage' => $safeError['stage']], $items),
        ];
    }

    private static function modelVisibleWidgetCanonicalUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, '://') && filter_var($url, FILTER_VALIDATE_URL) === false) return '';
        if (str_contains($url, '://')) {
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass'])) return '';
        }
        return $url;
    }

    private static function modelVisibleWidgetFileId(string $fileId): ?string
    {
        // ChatGPT may identify a trusted upload with a session-scoped URI such
        // as sediment://.... Echoing that URI in structuredContent makes the
        // host treat it as an MCP resource and attempt resources/read after
        // the canonical ingest has already succeeded. Keep transport refs in
        // the request only; expose ordinary opaque IDs when they are safe to
        // serialize as result metadata.
        return $fileId !== '' && !str_contains($fileId, '://') ? $fileId : null;
    }

    private function captureIngest(array $arguments, array $files): array
    {
        if (is_callable($this->runtimeWriteReady) && !(bool) ($this->runtimeWriteReady)()) throw new \RuntimeException('REQUIRED_SCHEMA_NOT_READY');
        $hasMediaIds = isset($arguments['media_ids']) && (array) $arguments['media_ids'] !== [];
        $hasExistingMediaUrls = isset($arguments['existing_media_urls']) && (array) $arguments['existing_media_urls'] !== [];
        $hasProvidedFiles = isset($arguments['files']) && (array) $arguments['files'] !== [];
        if (($hasMediaIds && ($hasProvidedFiles || $files !== [] || $hasExistingMediaUrls)) || ($hasExistingMediaUrls && ($hasProvidedFiles || $files !== []))) throw new \InvalidArgumentException('CAPTURE_PHYSICAL_INPUT_AMBIGUOUS');
        if (strtoupper(trim((string) ($arguments['resume_mode'] ?? ''))) === 'RETRY' && !isset($arguments['capture_id'])) throw new \InvalidArgumentException('CAPTURE_RETRY_REQUIRES_CAPTURE_ID');
        if (is_array($arguments['resume_children'] ?? null) && $arguments['resume_children'] !== [] && !isset($arguments['capture_id'])) {
            throw new \InvalidArgumentException('CAPTURE_RESUME_REQUIRES_CAPTURE_ID');
        }
        ($this->documentation ?? new McpDocumentationRegistry())->assertCheckpoint((array) ($arguments['documentation_checkpoint'] ?? []));
        $relationshipOnly = \NHK\Core\Application\Capture\CapturePurposePolicy::isRelationshipOnly($arguments);
        if (($arguments['dry_run'] ?? false) === true) {
            if ($this->isKnowledgeRepairPreviewRequest($arguments)) {
                if ($this->knowledgeRepairPreview === null) throw new \InvalidArgumentException('KNOWLEDGE_REPAIR_PREVIEW_REQUIRED');
                return ['status' => 'PREVIEW', 'preview' => $this->knowledgeRepairPreview->preview((array) $arguments['knowledge_repair'])];
            }
            if ($relationshipOnly) return $this->previewRelationshipOperations($arguments);
            throw new \InvalidArgumentException('CAPTURE_DRY_RUN_UNSUPPORTED');
        }
        unset($arguments['files']);
        if ($files !== []) {
            $arguments['files'] = $files;
            $arguments['_nhk_native_multipart'] = true;
        }
        $intent = is_array($arguments['authority_intent'] ?? null) ? $arguments['authority_intent'] : [];
        $declaredPurpose = strtoupper(trim((string) ($arguments['purpose'] ?? '')));
        $authorityPacket = in_array($declaredPurpose, ['AUTHORITY', 'MIXED'], true)
            || in_array((string) ($intent['mode'] ?? ''), ['PLAN', 'APPLY_APPROVED_PLAN'], true)
            || $relationshipOnly;
        if ($authorityPacket) {
            $this->assertAuthoritySemanticWriteAllowed();
            if ($this->authorityCapture === null) throw new \RuntimeException('AUTHORITY_CAPTURE_UNAVAILABLE');
            if (isset($arguments['capture_id'])) return $this->authorityCapture->continueWithApproval((string) $arguments['capture_id'], $arguments)->toArray();
            return $this->authorityCapture->execute($arguments)->toArray();
        }
        if ($this->capture === null) throw new \RuntimeException('EDITORIAL_CAPTURE_UNAVAILABLE');
        if (isset($arguments['capture_id'])) {
            if ($this->captureContinuation === null) throw new \RuntimeException('EDITORIAL_CAPTURE_CONTINUATION_UNAVAILABLE');
            if (strtoupper(trim((string) ($arguments['resume_mode'] ?? ''))) === 'RETRY') return $this->captureContinuation->retry($arguments);
            return $this->captureContinuation->execute($arguments);
        }
        return $this->capture->execute($arguments)->toArray();
    }

    /** @param array<string,mixed> $arguments */
    private function isKnowledgeRepairPreviewRequest(array $arguments): bool
    {
        return strtoupper(trim((string) ($arguments['intent'] ?? ''))) === 'KNOWLEDGE_REPAIR'
            && is_array($arguments['knowledge_repair'] ?? null)
            && $arguments['knowledge_repair'] !== [];
    }

    /** @param array<string,mixed> $arguments */
    private function previewRelationshipOperations(array $arguments): array
    {
        $previews = [];
        foreach ($arguments['relationship_operations'] as $operation) {
            if (!is_array($operation)) throw new \InvalidArgumentException('RELATION_OPERATION_MALFORMED');
            $previews[] = $this->read->relationshipPreview($operation);
        }
        return ['status' => 'PREVIEW', 'preview' => ['relationship_operations' => $previews]];
    }

    private function assertAuthoritySemanticWriteAllowed(): void
    {
        if ($this->semanticWritePolicy === null) return;
        $decision = $this->semanticWritePolicy->decision(true, $this->can ?? static fn (string $capability): bool => false);
        if (($decision['allowed'] ?? false) !== true) {
            $code = (string) ($decision['code'] ?? 'SEMANTIC_WRITE_POLICY_READ_ONLY');
            throw new SemanticWritePolicyViolation($code, $code, $decision);
        }
    }

    /** @return array<string,mixed> */
    private function runtimeIdentity(): array
    {
        $identity = ($this->documentation ?? new McpDocumentationRegistry())->runtimeIdentity();
        if ($this->semanticWritePolicy === null) return $identity;
        $identity['environment'] = $this->semanticWritePolicy->environment();
        $identity['semantic_write_policy'] = $this->semanticWritePolicy->resolve()->value;
        $identity['project_build_enabled'] = $this->semanticWritePolicy->projectBuildEnabled();
        return $identity;
    }

    private function validateArguments(array $schema, array $arguments): void
    {
        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $this->validateArgumentValue('arguments', $arguments, $schema);
            return;
        }
        foreach ((array) ($schema['required'] ?? []) as $key) {
            if (!array_key_exists((string) $key, $arguments)) throw new \InvalidArgumentException('Missing required argument: ' . $key . '.');
        }
        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($arguments) as $key) if (!array_key_exists((string) $key, (array) ($schema['properties'] ?? []))) throw new \InvalidArgumentException('Unknown argument: ' . $key . '.');
        }
        foreach ((array) ($schema['properties'] ?? []) as $key => $property) {
            if (!array_key_exists($key, $arguments)) continue;
            $this->validateArgumentValue((string) $key, $arguments[$key], is_array($property) ? $property : []);
        }
    }

    private function validateArgumentValue(string $key, mixed $value, array $schema): void
    {
        if (isset($schema['oneOf']) && is_array($schema['oneOf'])) {
            $matches = 0;
            $failures = [];
            foreach (array_values($schema['oneOf']) as $index => $variant) {
                if (!is_array($variant)) continue;
                try {
                    $this->validateArgumentValue($key, $value, $variant);
                    ++$matches;
                } catch (\InvalidArgumentException $error) {
                    $failures[$index] = $error->getMessage();
                }
            }
            if ($matches > 1) throw new \InvalidArgumentException('ONE_OF_AMBIGUOUS: ' . $key . '.');
            if ($matches === 0) {
                $details = [];
                foreach ($failures as $index => $failure) $details[] = 'branch[' . $index . ']: ' . $failure;
                throw new \InvalidArgumentException('ONE_OF_NO_MATCH: ' . $key . '. ' . implode(' | ', $details));
            }
            return;
        }
        $types = (array) ($schema['type'] ?? '');
        $valid = match (true) {
            $value === null => in_array('null', $types, true),
            in_array('string', $types, true) => is_string($value),
            in_array('integer', $types, true) => is_int($value),
            in_array('number', $types, true) => is_int($value) || is_float($value),
            in_array('object', $types, true), in_array('array', $types, true) => is_array($value),
            default => true,
        };
        if (!$valid) throw new \InvalidArgumentException('Argument has invalid type: ' . $key . '.');
        if ($value === null) return;
        if (($schema['format'] ?? '') === 'uuid' && (!is_string($value) || !UuidCodec::isValid($value))) throw new \InvalidArgumentException('Argument has invalid format: ' . $key . '.');
        if (($schema['format'] ?? '') === 'uri' && (!is_string($value) || filter_var($value, FILTER_VALIDATE_URL) === false)) throw new \InvalidArgumentException('Argument has invalid format: ' . $key . '.');
        if (array_key_exists('const', $schema) && $value !== $schema['const']) throw new \InvalidArgumentException('Argument has invalid discriminator: ' . $key . '.');
        if (isset($schema['pattern']) && is_string($value) && preg_match('/' . $schema['pattern'] . '/', $value) !== 1) throw new \InvalidArgumentException('Argument has invalid format: ' . $key . '.');
        if (isset($schema['enum']) && !in_array($value, (array) $schema['enum'], true)) throw new \InvalidArgumentException('Argument has invalid value: ' . $key . '.');
        if (isset($schema['minLength']) && is_string($value) && strlen($value) < (int) $schema['minLength']) throw new \InvalidArgumentException('Argument is too short: ' . $key . '.');
        if (isset($schema['minimum']) && (is_int($value) || is_float($value)) && $value < (float) $schema['minimum']) throw new \InvalidArgumentException('Argument is below minimum: ' . $key . '.');
        if (isset($schema['maximum']) && (is_int($value) || is_float($value)) && $value > (float) $schema['maximum']) throw new \InvalidArgumentException('Argument is above maximum: ' . $key . '.');
        if (($schema['type'] ?? '') === 'object') {
            foreach ((array) ($schema['required'] ?? []) as $required) if (!array_key_exists((string) $required, $value)) throw new \InvalidArgumentException('Missing required argument: ' . $key . '.' . $required . '.');
            if (($schema['additionalProperties'] ?? true) === false) foreach (array_keys($value) as $property) if (!array_key_exists((string) $property, (array) ($schema['properties'] ?? []))) throw new \InvalidArgumentException('Unknown argument: ' . $key . '.' . $property . '.');
            foreach ((array) ($schema['properties'] ?? []) as $property => $propertySchema) if (array_key_exists($property, $value)) $this->validateArgumentValue($key . '.' . $property, $value[$property], is_array($propertySchema) ? $propertySchema : []);
        }
        if (($schema['type'] ?? '') === 'array' && isset($schema['items']) && is_array($schema['items'])) foreach ($value as $item) $this->validateArgumentValue($key . '[]', $item, $schema['items']);
        if (($schema['type'] ?? '') === 'array' && isset($schema['minItems']) && count($value) < (int) $schema['minItems']) throw new \InvalidArgumentException('Argument has too few items: ' . $key . '.');
        if (($schema['type'] ?? '') === 'array' && isset($schema['maxItems']) && count($value) > (int) $schema['maxItems']) throw new \InvalidArgumentException('Argument has too many items: ' . $key . '.');
    }

    private function isModern(array $request, array $params, array $headers): bool
    {
        return $this->header($headers, 'MCP-Protocol-Version') !== '' || (string) ($params['protocolVersion'] ?? '') === self::MODERN_VERSION || (string) ($this->meta($request, $params)['io.modelcontextprotocol/protocolVersion'] ?? '') === self::MODERN_VERSION;
    }

    private function meta(array $request, array $params): array
    {
        $meta = $params['_meta'] ?? ($request['_meta'] ?? []);
        return is_array($meta) ? $meta : [];
    }

    private function header(array $headers, string $name): string
    {
        $wanted = strtolower(str_replace('_', '-', $name));
        foreach ($headers as $key => $value) if (strtolower(str_replace('_', '-', (string) $key)) === $wanted) return is_array($value) ? (string) reset($value) : (string) $value;
        return '';
    }

    private function acceptsStreamableHttp(array $headers): bool
    {
        $accepted = [];
        foreach (explode(',', strtolower($this->header($headers, 'Accept'))) as $part) $accepted[] = trim(explode(';', $part, 2)[0]);
        return in_array('application/json', $accepted, true) && in_array('text/event-stream', $accepted, true);
    }

    private function required(array $arguments, string $key): string
    {
        $value = trim((string) ($arguments[$key] ?? ''));
        if ($value === '') throw new \InvalidArgumentException('Missing required argument: ' . $key . '.');
        return $value;
    }

    private function mediaIngest(array $arguments, array $files = []): array
    {
        $attachment = $this->fileAttachment($arguments, $files);
        if ($attachment !== null) {
            if ($this->wordpressAttachments === null) throw new \RuntimeException('WORDPRESS_MEDIA_INGEST_UNAVAILABLE');
            $filename = trim((string) ($arguments['filename'] ?? ''));
            if ($filename === '') throw new \InvalidArgumentException('filename is required for file ingest.');
            return $this->wordpressAttachments->ingest(
                $attachment,
                $filename,
                (string) ($arguments['name'] ?? ''),
                (int) ($arguments['max_width'] ?? \NHK\Core\Application\Media\PublicImageSizingPolicy::MAX_LONG_EDGE),
                (int) ($arguments['max_height'] ?? \NHK\Core\Application\Media\PublicImageSizingPolicy::MAX_LONG_EDGE),
                (int) ($arguments['quality'] ?? \NHK\Core\Application\Media\PublicMediaAssetSelector::DEFAULT_WEBP_QUALITY),
            );
        }
        if (array_key_exists('file', $arguments)) throw new \InvalidArgumentException('file must be a direct multipart attachment.');
        if (trim((string) ($arguments['stable_key'] ?? '')) === '') throw new \InvalidArgumentException('Missing required argument: stable_key.');
        $mediaArguments = $arguments;
        $mediaArguments['operation'] = 'ingest';
        $mediaArguments['entity_type'] = 'media';
        $mediaArguments['payload'] = [
            'stable_key' => (string) ($arguments['stable_key'] ?? ''),
            'name' => (string) ($arguments['name'] ?? ''),
            'readiness' => (string) ($arguments['readiness'] ?? 'draft'),
            'provenance' => is_array($arguments['provenance'] ?? null) ? $arguments['provenance'] : [],
            'assets' => is_array($arguments['assets'] ?? null) ? $arguments['assets'] : [],
            'usages' => is_array($arguments['usages'] ?? null) ? $arguments['usages'] : [],
        ];
        return $this->ingestProposal($this->governance->createFromArguments($mediaArguments));
    }

    /** Route system-selected representative bindings through the shared Governance pipeline. */
    private function mediaBind(array $arguments): array
    {
        if ($this->mediaBinding === null) throw new \RuntimeException('MEDIA_BINDING_SERVICE_UNAVAILABLE');
        $source = strtoupper(trim((string) ($arguments['selection_source'] ?? 'USER_EXPLICIT')));
        if ($source !== 'SYSTEM_AUTO') return $this->mediaBinding->bind($arguments);
        $media = $this->mediaBinding->resolveMediaReference(is_array($arguments['media'] ?? null) ? $arguments['media'] : []);
        $targetReference = is_array($arguments['target'] ?? null) ? $arguments['target'] : [];
        $target = $this->mediaBinding->resolveTargetReference($targetReference);
        $binding = $arguments;
        $binding['selection_source'] = 'SYSTEM_AUTO';
        $binding['selection_policy'] = 'AUTO';
        $binding['media'] = ['id' => $media->canonicalId];
        $binding['target'] = ['type' => $target->entityType, 'id' => $target->canonicalId];
        $proposal = $this->governance->createFromArguments([
            'operation' => 'representative_bind',
            'entity_type' => 'media',
            'subject_id' => $media->canonicalId,
            'target_uuid' => $target->canonicalId,
            'expected_revision' => $media->revision,
            'idempotency_key' => (string) ($arguments['idempotency_key'] ?? ''),
            'payload' => [
                'binding' => $binding,
                'media_revision' => $media->revision,
                'target_revision' => $target->revision,
                'target_uuid' => $target->canonicalId,
            ],
        ]);
        return $this->ingestProposal($proposal);
    }

    /** Create a bounded existing-Media metadata Proposal; apply remains Governance-owned. */
    private function mediaUpdate(array $arguments): array
    {
        if ($this->mediaBinding === null) throw new \RuntimeException('MEDIA_UPDATE_SERVICE_UNAVAILABLE');
        $media = $this->mediaBinding->resolveMediaReference((array) ($arguments['media_ref'] ?? []));
        $payload = ['operation' => 'update', 'media' => ['id' => $media->canonicalId]];
        foreach (['name', 'readiness', 'provenance'] as $field) {
            if (array_key_exists($field, $arguments)) $payload[$field] = $arguments[$field];
        }
        if (count($payload) === 2) throw new \InvalidArgumentException('MEDIA_UPDATE_DELTA_REQUIRED');
        $proposal = $this->governance->createFromArguments([
            'operation' => 'update',
            'entity_type' => 'media',
            'subject_id' => $media->canonicalId,
            'expected_revision' => (int) $arguments['expected_revision'],
            'idempotency_key' => (string) $arguments['idempotency_key'],
            'payload' => $payload,
        ]);
        return $this->ingestProposal($proposal);
    }

    /** Route every generic MediaUsage mutation through the shared Governance pipeline. */
    private function mediaUsage(array $arguments): array
    {
        if ($this->mediaBinding === null) throw new \RuntimeException('MEDIA_BINDING_SERVICE_UNAVAILABLE');
        $media = $this->mediaBinding->resolveMediaReference((array) ($arguments['media'] ?? []));
        $targetReference = is_array($arguments['target'] ?? null) ? $arguments['target'] : [];
        $targetType = strtolower(trim((string) ($targetReference['type'] ?? '')));
        $targetUuid = null;
        $target = $targetReference;
        if ($targetType !== 'wp_post') {
            $resolved = $this->mediaBinding->resolveTargetReference($targetReference);
            $targetUuid = $resolved->canonicalId;
            $target = ['type' => $resolved->entityType, 'id' => $resolved->canonicalId];
        }
        $operation = strtolower(trim((string) ($arguments['operation'] ?? '')));
        $payload = $arguments;
        $payload['media'] = ['id' => $media->canonicalId];
        $payload['target'] = $target;
        $proposal = $this->governance->createFromArguments([
            'operation' => $operation,
            'entity_type' => 'media',
            'subject_id' => $media->canonicalId,
            'target_uuid' => $targetUuid,
            'expected_revision' => $operation === 'representative_bind' ? $media->revision : null,
            'idempotency_key' => (string) ($arguments['idempotency_key'] ?? ''),
            'payload' => $payload,
        ]);
        return $this->ingestProposal($proposal);
    }

    /** @return array<string,mixed> */
    private function fileArgumentMetadata(array $arguments, array $files): array
    {
        $batch = $files['files'] ?? null;
        if (is_array($batch)) {
                $metadata = [];
                if (is_array($batch['tmp_name'] ?? null)) {
                    foreach ($batch['tmp_name'] as $index => $tmpName) $metadata[] = ['name' => is_array($batch['name'] ?? null) ? (string) ($batch['name'][$index] ?? '') : '', 'type' => is_array($batch['type'] ?? null) ? (string) ($batch['type'][$index] ?? '') : '', 'size' => is_array($batch['size'] ?? null) ? (int) ($batch['size'][$index] ?? 0) : 0];
                }
                $walk = function (mixed $value) use (&$walk, &$metadata): void {
                    if (!is_array($value)) return;
                    if (array_key_exists('tmp_name', $value) && is_array($value['tmp_name'])) return;
                    if (array_key_exists('tmp_name', $value)) { $metadata[] = ['name' => (string) ($value['name'] ?? ''), 'type' => (string) ($value['type'] ?? ''), 'size' => (int) ($value['size'] ?? 0)]; return; }
                    foreach ($value as $nested) $walk($nested);
                };
                $walk($batch);
                if ($metadata !== []) $arguments['files'] = $metadata;
        }
        if (array_key_exists('file', $arguments)) {
            if (is_string($arguments['file']) && isset($files[$arguments['file']]) && is_array($files[$arguments['file']])) {
                $file = $files[$arguments['file']];
                $arguments['file'] = ['name' => (string) ($file['name'] ?? ''), 'type' => (string) ($file['type'] ?? ''), 'size' => (int) ($file['size'] ?? 0)];
            }
            return $arguments;
        }
        $file = $this->fileAttachment($arguments, $files);
        if ($file === null) return $arguments;
        $arguments['file'] = [
            'name' => (string) ($file['name'] ?? ''),
            'type' => (string) ($file['type'] ?? ''),
            'size' => (int) ($file['size'] ?? 0),
        ];
        return $arguments;
    }

    /** @return array<string,mixed>|null */
    private function fileAttachment(array $arguments, array $files): ?array
    {
        if ($files === []) return null;
        $requested = $arguments['file'] ?? null;
        if (is_string($requested) && isset($files[$requested]) && is_array($files[$requested])) return $this->normalizeUploadedFile($files[$requested]);
        if (is_array($requested) && isset($requested['field']) && is_string($requested['field']) && isset($files[$requested['field']]) && is_array($files[$requested['field']])) return $this->normalizeUploadedFile($files[$requested['field']]);
        if (isset($files['file']) && is_array($files['file'])) return $this->normalizeUploadedFile($files['file']);
        foreach ($files as $file) {
            if (is_array($file) && isset($file['tmp_name'])) return $this->normalizeUploadedFile($file);
            if (is_array($file)) foreach ($file as $nested) if (is_array($nested) && isset($nested['tmp_name'])) return $this->normalizeUploadedFile($nested);
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private function normalizeUploadedFile(array $file): ?array
    {
        return isset($file['tmp_name']) && is_string($file['tmp_name']) ? $file : null;
    }

    private function videoIngest(array $arguments): array
    {
        // Keep the original proposal shape available to existing callers. The
        // enriched intake contract is selected explicitly by one of its new
        // fields, so upgrading the plugin does not silently change old jobs.
        $enriched = $this->videoIntake !== null && array_intersect(
            ['user_hint', 'intended_category', 'intended_relations', 'editorial_instruction', 'idempotency_key'],
            array_keys($arguments),
        ) !== [];
        if ($enriched) {
            $preview = $this->videoIntake->preview(
                (string) ($arguments['url'] ?? ''),
                (string) ($arguments['user_hint'] ?? ''),
                isset($arguments['intended_category']) ? (string) $arguments['intended_category'] : null,
                is_array($arguments['intended_relations'] ?? null) ? $arguments['intended_relations'] : [],
                (string) ($arguments['editorial_instruction'] ?? ''),
            );
            $proposal = $this->governance->createFromArguments($this->videoIntake->proposalArguments($preview, isset($arguments['idempotency_key']) ? (string) $arguments['idempotency_key'] : null));
            $result = $this->ingestProposal($proposal);
            $result['preview'] = $preview->toArray();
            return $result;
        }
        $videoArguments = $arguments;
        $videoArguments['operation'] = 'ingest';
        $videoArguments['entity_type'] = 'video';
        // The public legacy shape accepts only the external URL, so bind the
        // governed proposal to a canonical Video identity before dispatch.
        // This keeps the schema-compatible path inside the same strict
        // subject-binding contract as the enriched intake path.
        $videoArguments['subject_id'] = UuidCodec::newV7();
        $videoArguments['payload'] = [
            'canonical_id' => $videoArguments['subject_id'],
            'url' => (string) ($arguments['url'] ?? ''),
            'title' => (string) ($arguments['title'] ?? ''),
            'metadata' => is_array($arguments['metadata'] ?? null) ? $arguments['metadata'] : [],
            'thumbnail_media_id' => (string) ($arguments['thumbnail_media_id'] ?? ''),
        ];
        return $this->ingestProposal($this->governance->createFromArguments($videoArguments));
    }

    private function knowledgeIngest(array $arguments): array
    {
        $knowledgeArguments = $arguments;
        $knowledgeArguments['operation'] = 'ingest';
        $knowledgeArguments['entity_type'] = 'knowledge';
        $knowledgeArguments['payload'] = [
            'stable_key' => (string) ($arguments['stable_key'] ?? ''),
            'text' => (string) ($arguments['text'] ?? ''),
            'claim_type' => (string) ($arguments['claim_type'] ?? 'fact'),
            'provenance' => is_array($arguments['provenance'] ?? null) ? $arguments['provenance'] : [],
        ];
        return $this->ingestProposal($this->governance->createFromArguments($knowledgeArguments));
    }

    private function sourceIngest(array $arguments): array
    {
        $sourceArguments = $arguments;
        $sourceArguments['operation'] = 'ingest';
        $sourceArguments['entity_type'] = 'source';
        $sourceArguments['payload'] = [
            'stable_key' => (string) ($arguments['stable_key'] ?? ''),
            'title' => (string) ($arguments['title'] ?? ''),
            'source_type' => (string) ($arguments['source_type'] ?? 'website'),
            'locator' => isset($arguments['locator']) ? (string) $arguments['locator'] : null,
            'metadata' => $this->withVisibility($arguments),
        ];
        return $this->ingestProposal($this->governance->createFromArguments($sourceArguments));
    }

    private function evidenceIngest(array $arguments): array
    {
        if ($this->dependencies === null) throw new \RuntimeException('CANONICAL_DEPENDENCY_VALIDATOR_UNAVAILABLE');
        $this->dependencies->claim((string) ($arguments['claim_id'] ?? ''), 'claim_id');
        $this->dependencies->source((string) ($arguments['source_id'] ?? ''), 'source_id');
        $evidenceArguments = $arguments;
        $evidenceArguments['operation'] = 'ingest';
        $evidenceArguments['entity_type'] = 'evidence';
        $evidenceArguments['payload'] = [
            'claim_id' => (string) ($arguments['claim_id'] ?? ''),
            'source_id' => (string) ($arguments['source_id'] ?? ''),
            'excerpt' => (string) ($arguments['excerpt'] ?? ''),
            'relation' => (string) ($arguments['relation'] ?? 'supports'),
            'locator' => isset($arguments['locator']) ? (string) $arguments['locator'] : null,
            'metadata' => $this->withVisibility($arguments),
        ];
        return $this->ingestProposal($this->governance->createFromArguments($evidenceArguments));
    }

    private function proposal(\NHK\Core\Domain\Governance\Proposal $proposal): array
    {
        return ['id' => $proposal->id, 'subject_id' => $proposal->subjectId, 'entity_type' => $proposal->entityType, 'operation' => $proposal->operation, 'payload' => $proposal->payload, 'state' => $proposal->state->value, 'expected_revision' => $proposal->expectedRevision, 'revision' => $proposal->revision, 'idempotency_key' => $proposal->idempotencyKey, 'target_uuid' => $proposal->targetUuid];
    }

    /** @return array<string,mixed> */
    private function ingestProposal(\NHK\Core\Domain\Governance\Proposal $proposal): array
    {
        if (method_exists($this->governance, 'automationEnabled') && $this->governance->automationEnabled()) {
            return $this->governance->ingestFromArguments([
                'operation' => $proposal->operation,
                'entity_type' => $proposal->entityType,
                'subject_id' => $proposal->subjectId,
                'target_uuid' => $proposal->targetUuid,
                'target' => is_array($proposal->payload['target'] ?? null) ? $proposal->payload['target'] : (is_array($proposal->payload['binding']['target'] ?? null) ? $proposal->payload['binding']['target'] : []),
                'expected_revision' => $proposal->expectedRevision,
                'payload' => $proposal->payload,
                'content_fingerprint' => $proposal->contentFingerprint,
                'dependency_fingerprint' => $proposal->dependencyFingerprint,
                'idempotency_key' => $proposal->idempotencyKey,
            ]);
        }
        return [
            'proposal_id' => $proposal->id,
            'proposal_state' => $proposal->state->value,
            'target_uuid' => $proposal->targetUuid,
            'canonical_id' => $proposal->targetUuid,
            'entity_type' => $proposal->entityType,
            'operation' => $proposal->operation,
            'payload' => $proposal->payload,
            'expected_revision' => $proposal->expectedRevision,
            'revision' => $proposal->revision,
            'idempotency_key' => $proposal->idempotencyKey,
        ];
    }

    /** @return array<string,mixed> */
    private function withVisibility(array $arguments): array
    {
        $metadata = is_array($arguments['metadata'] ?? null) ? $arguments['metadata'] : [];
        if (!array_key_exists('visibility', $arguments)) return $metadata;
        $visibility = (string) $arguments['visibility'];
        if (array_key_exists('visibility', $metadata) && strtoupper(trim((string) $metadata['visibility'])) !== $visibility) {
            throw new \InvalidArgumentException('Top-level visibility conflicts with metadata.visibility.');
        }
        $metadata['visibility'] = $visibility;
        return $metadata;
    }

    /** @return array{status:int,body:array} */
    private function error(mixed $id, int $code, string $message, int $status, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) $error['data'] = $data;
        return ['status' => $status, 'body' => ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]];
    }
}

final class McpMethodNotFound extends \RuntimeException {}
final class McpPermissionDenied extends \RuntimeException {}
