<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

use NHK\Core\Shared\Uuid\UuidCodec;
use NHK\Core\Application\Video\VideoIntakeService;
use NHK\Core\Contracts\Media\WordPressMediaAttachmentIngestor;
use NHK\Core\Application\WordPress\{CategoryGateway, EditorialDraftGateway};
use NHK\Core\Application\Knowledge\CanonicalDependencyValidator;
use NHK\Core\Application\PublicIdentity\PublicUrlMaintenanceService;
use NHK\Core\Domain\Knowledge\DependencyValidationException;

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
        } catch (\InvalidArgumentException $error) {
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
            'server/discover' => ['protocolVersions' => [self::MODERN_VERSION, self::LEGACY_VERSION], 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => ['name' => 'nhk-v3', 'version' => '3.0.0']],
            'initialize' => ['protocolVersion' => $modern ? self::MODERN_VERSION : self::LEGACY_VERSION, 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => ['name' => 'nhk-v3', 'version' => '3.0.0']],
            'tools/list' => ['tools' => array_map(static fn (array $tool): array => ['name' => $tool['name'], 'description' => $tool['description'], 'inputSchema' => $tool['inputSchema']], McpToolCatalog::tools())],
            'tools/call' => $this->callTool($params, $files),
            default => throw new McpMethodNotFound($method),
        };
    }

    private function callTool(array $params, array $files = []): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        if ($name === 'nhk.media.ingest') $arguments = $this->fileArgumentMetadata($arguments, $files);
        $definition = null;
        foreach (McpToolCatalog::tools() as $tool) if ($tool['name'] === $name) { $definition = $tool; break; }
        if ($definition === null) throw new McpMethodNotFound('tools/call:' . $name);
        $capability = match ($name) {
            'nhk.article.preflight' => 'read',
            'nhk.article.ingest' => 'nhk_ingest_articles',
            'nhk.category.create', 'nhk.category.update', 'nhk.category.assign', 'nhk.category.unassign', 'nhk.category.delete', 'nhk.article.draft.create', 'nhk.article.draft.update', 'nhk.article.publish', 'nhk.article.publish.review', 'nhk.article.publish.approve', 'nhk.article.trash', 'nhk.article.restore' => 'nhk_ingest_articles',
            'nhk.proposal.create' => 'nhk_create_proposals',
            'nhk.media.ingest' => 'nhk_create_proposals',
            'nhk.video.ingest' => 'nhk_create_proposals',
            'nhk.knowledge.ingest', 'nhk.source.ingest', 'nhk.evidence.ingest' => 'nhk_create_proposals',
            'nhk.proposal.submit' => 'nhk_submit_proposals',
            'nhk.proposal.review' => 'nhk_view_governance',
            'nhk.proposal.approve', 'nhk.proposal.reject' => 'nhk_approve_proposals',
            'nhk.proposal.eligibility' => 'nhk_view_governance',
            'nhk.proposal.apply' => 'nhk_apply_proposals',
            'nhk.public-url.audit', 'nhk.public-url.reproject' => 'nhk_manage_public_urls',
            default => null,
        };
        if ($capability !== null && (!$this->can || !(bool) ($this->can)($capability))) throw new McpPermissionDenied($capability);
        $this->validateArguments($definition['inputSchema'], $arguments);
        $result = match ($name) {
            'nhk.public-url.audit' => $this->publicUrls?->audit() ?? throw new \RuntimeException('PUBLIC_URL_MAINTENANCE_UNAVAILABLE'),
            'nhk.public-url.reproject' => $this->publicUrls?->reproject((string) ($arguments['idempotency_key'] ?? ''), (bool) ($arguments['pre_public_confirmed'] ?? false)) ?? throw new \RuntimeException('PUBLIC_URL_MAINTENANCE_UNAVAILABLE'),
            'nhk.search' => $this->read->search((string) ($arguments['q'] ?? ''), (int) ($arguments['page'] ?? 1), (int) ($arguments['per_page'] ?? 20)),
            'nhk.semantic.resolve' => $this->read->semanticResolve((array) ($arguments['context'] ?? [])),
            'nhk.article.preflight' => $this->article?->preflight($arguments) ?? throw new \RuntimeException('ARTICLE_INGEST_HANDLER_UNAVAILABLE'),
            'nhk.article.ingest' => $this->article?->ingest($arguments) ?? throw new \RuntimeException('ARTICLE_INGEST_HANDLER_UNAVAILABLE'),
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
            'nhk.media.ingest' => $this->mediaIngest($arguments, $files),
            'nhk.media.attachment.get' => $this->read->mediaAttachmentGet((int) ($arguments['attachment_id'] ?? 0)),
            'nhk.video.ingest' => $this->videoIngest($arguments),
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
        };
        $text = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $result, 'isError' => false];
    }

    private function validateArguments(array $schema, array $arguments): void
    {
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
                (int) ($arguments['max_width'] ?? 2048),
                (int) ($arguments['max_height'] ?? 2048),
                (int) ($arguments['quality'] ?? 82),
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

    /** @return array<string,mixed> */
    private function fileArgumentMetadata(array $arguments, array $files): array
    {
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
        $videoArguments['payload'] = [
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
