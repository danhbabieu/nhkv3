<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

final class McpAbilityRegistration
{
    private const CATEGORY = 'nhk-v3-content-operations';

    /** @var array<string,string> */
    private const READ_TOOL_MAP = [
        'nhk.search' => 'nhk-v3/search',
        'nhk.semantic.resolve' => 'nhk-v3/semantic-resolve',
        'nhk.article.preflight' => 'nhk-v3/article-preflight',
        'nhk.category.resolve' => 'nhk-v3/category-resolve',
        'nhk.entity.get' => 'nhk-v3/entity-get',
        'nhk.media.get' => 'nhk-v3/media-get',
        'nhk.media.attachment.get' => 'nhk-v3/media-attachment-get',
        'nhk.video.get' => 'nhk-v3/video-get',
        'nhk.knowledge.get' => 'nhk-v3/knowledge-get',
        'nhk.source.get' => 'nhk-v3/source-get',
        'nhk.evidence.get' => 'nhk-v3/evidence-get',
    ];

    /** @var array<string,string> */
    private const CAPABILITY_GATED_READ_TOOL_MAP = [
        'nhk.proposal.eligibility' => 'nhk-v3/proposal-eligibility',
        'nhk.proposal.review' => 'nhk-v3/proposal-review',
        'nhk.public-url.audit' => 'nhk-v3/public-url-audit',
    ];

    /** @var array<string,string> */
    private const GOVERNED_TOOL_MAP = [
        'nhk.public-url.reproject' => 'nhk-v3/public-url-reproject',
        'nhk.article.ingest' => 'nhk-v3/article-ingest',
        'nhk.category.create' => 'nhk-v3/category-create',
        'nhk.category.update' => 'nhk-v3/category-update',
        'nhk.category.assign' => 'nhk-v3/category-assign',
        'nhk.category.unassign' => 'nhk-v3/category-unassign',
        'nhk.category.delete' => 'nhk-v3/category-delete',
        'nhk.article.draft.create' => 'nhk-v3/article-draft-create',
        'nhk.article.draft.update' => 'nhk-v3/article-draft-update',
        'nhk.article.publish' => 'nhk-v3/article-publish',
        'nhk.article.publish.review' => 'nhk-v3/article-publish-review',
        'nhk.article.publish.approve' => 'nhk-v3/article-publish-approve',
        'nhk.article.trash' => 'nhk-v3/article-trash',
        'nhk.article.restore' => 'nhk-v3/article-restore',
        'nhk.video.ingest' => 'nhk-v3/video-ingest',
        'nhk.knowledge.ingest' => 'nhk-v3/knowledge-ingest',
        'nhk.source.ingest' => 'nhk-v3/source-ingest',
        'nhk.evidence.ingest' => 'nhk-v3/evidence-ingest',
        'nhk.proposal.create' => 'nhk-v3/proposal-create',
        'nhk.proposal.submit' => 'nhk-v3/proposal-submit',
        'nhk.proposal.approve' => 'nhk-v3/proposal-approve',
        'nhk.proposal.reject' => 'nhk-v3/proposal-reject',
        'nhk.proposal.apply' => 'nhk-v3/proposal-apply',
    ];

    /** @var array<string,string> */
    private const EXPLICIT_EXCLUSION_REASONS = [
        'nhk.media.ingest' => 'multipart canonical transport; WordPress Ability input cannot carry the file part',
    ];

    /** @return list<string> */
    public static function readAbilityNames(): array
    {
        return array_values(self::READ_TOOL_MAP);
    }

    /** @return list<string> */
    public static function capabilityGatedReadAbilityNames(): array
    {
        return array_values(self::CAPABILITY_GATED_READ_TOOL_MAP);
    }

    public static function abilityNameForTool(string $tool): ?string
    {
        return self::READ_TOOL_MAP[$tool] ?? self::CAPABILITY_GATED_READ_TOOL_MAP[$tool] ?? self::GOVERNED_TOOL_MAP[$tool] ?? null;
    }

    /** @return list<string> */
    public static function governedAbilityNames(): array
    {
        return array_values(self::GOVERNED_TOOL_MAP);
    }

    /** @return list<string> */
    public static function abilityNames(): array
    {
        return array_values(array_merge(self::READ_TOOL_MAP, self::CAPABILITY_GATED_READ_TOOL_MAP, self::GOVERNED_TOOL_MAP));
    }

    /** @return array<string,string> */
    public static function explicitExclusionReasons(): array
    {
        return self::EXPLICIT_EXCLUSION_REASONS;
    }

    public static function registerCategory(): void
    {
        if (!function_exists('wp_register_ability_category')) return;
        wp_register_ability_category(self::CATEGORY, [
            'label' => 'NHK V3 Content Operations',
            'description' => 'NHK V3 Article, media, video, category and governed content operations.',
        ]);
    }

    public static function registerReadAbilities(McpReadHandler $read): void
    {
        if (!function_exists('wp_register_ability')) return;
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (self::READ_TOOL_MAP as $toolName => $abilityName) {
            $tool = $tools[$toolName] ?? null;
            if (!is_array($tool) || ($tool['kind'] ?? null) !== 'read' || ($tool['governed'] ?? true) !== false) continue;
            wp_register_ability($abilityName, [
                'label' => self::label($toolName),
                'description' => (string) $tool['description'],
                'category' => self::CATEGORY,
                'input_schema' => (array) $tool['inputSchema'],
                'output_schema' => ['type' => ['object', 'null']],
                'execute_callback' => static fn (mixed $input = null): mixed => self::execute($toolName, $read, $input),
                'permission_callback' => static fn (): bool => self::canRead($toolName),
                'meta' => [
                    'public' => true,
                    'show_in_rest' => true,
                    'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                ],
            ]);
        }
    }

    public static function registerCapabilityGatedReadAbilities(): void
    {
        if (!function_exists('wp_register_ability') || !function_exists('rest_do_request')) return;
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (self::CAPABILITY_GATED_READ_TOOL_MAP as $toolName => $abilityName) {
            $tool = $tools[$toolName] ?? null;
            if (!is_array($tool) || ($tool['kind'] ?? null) !== 'read' || ($tool['governed'] ?? true) !== false) continue;
            wp_register_ability($abilityName, [
                'label' => self::label($toolName),
                'description' => (string) $tool['description'],
                'category' => self::CATEGORY,
                'input_schema' => (array) $tool['inputSchema'],
                'output_schema' => ['type' => ['object', 'null']],
                'execute_callback' => static fn (mixed $input = null): mixed => self::executeMcp($toolName, $input),
                'permission_callback' => static fn (): bool => self::canGoverned($toolName),
                'meta' => [
                    'public' => true,
                    'show_in_rest' => true,
                    'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                ],
            ]);
        }
    }

    /**
     * Register the small governed bridge consumed by WordPress/Easy MCP.
     *
     * The callback delegates to the already-registered NHK MCP endpoint. This
     * keeps validation, capability checks, Governance lifecycle and audit on
     * the one custom MCP transport; an Ability is discoverability only.
     */
    public static function registerGovernedAbilities(): void
    {
        if (!function_exists('wp_register_ability') || !function_exists('rest_do_request')) return;
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (self::GOVERNED_TOOL_MAP as $toolName => $abilityName) {
            $tool = $tools[$toolName] ?? null;
            if (!is_array($tool) || ($tool['kind'] ?? null) !== 'mutation' || ($tool['governed'] ?? false) !== true) continue;
            wp_register_ability($abilityName, [
                'label' => self::label($toolName),
                'description' => (string) $tool['description'],
                'category' => self::CATEGORY,
                'input_schema' => (array) $tool['inputSchema'],
                'output_schema' => ['type' => ['object', 'null']],
                'execute_callback' => static fn (mixed $input = null): mixed => self::executeMcp($toolName, $input),
                'permission_callback' => static fn (): bool => self::canGoverned($toolName),
                'meta' => [
                    'public' => true,
                    'show_in_rest' => true,
                    'annotations' => [
                        'readonly' => false,
                        'destructive' => in_array($toolName, ['nhk.category.delete', 'nhk.article.publish', 'nhk.article.trash', 'nhk.proposal.reject', 'nhk.proposal.apply'], true),
                        'idempotent' => true,
                    ],
                ],
            ]);
        }
    }

    private static function executeMcp(string $tool, mixed $input): mixed
    {
        $request = new \WP_REST_Request('POST', '/nhk/v1/mcp');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => is_array($input) ? $input : []],
        ]));
        $response = rest_do_request($request);
        if (is_wp_error($response)) return $response;
        $data = $response->get_data();
        if (!is_array($data)) return new \WP_Error('nhk_mcp_invalid_response', 'NHK V3 MCP returned an invalid response.', ['status' => 502]);
        if (isset($data['error'])) return new \WP_Error('nhk_mcp_' . (string) ($data['error']['code'] ?? 'error'), (string) ($data['error']['message'] ?? 'NHK V3 MCP call failed.'), ['status' => $response->get_status()]);
        $result = $data['result'] ?? null;
        if (!is_array($result)) return new \WP_Error('nhk_mcp_invalid_response', 'NHK V3 MCP returned no result.', ['status' => 502]);
        if (($result['isError'] ?? false) === true) return new \WP_Error('nhk_mcp_operation_error', (string) (($result['content'][0]['text'] ?? 'NHK V3 MCP call failed.')), ['status' => 422]);
        return $result['structuredContent'] ?? null;
    }

    private static function executeGoverned(string $tool, mixed $input): mixed
    {
        return self::executeMcp($tool, $input);
    }

    private static function canGoverned(string $tool): bool
    {
        $capability = match ($tool) {
            'nhk.article.ingest', 'nhk.category.create', 'nhk.category.update', 'nhk.category.assign', 'nhk.category.unassign', 'nhk.category.delete', 'nhk.article.draft.create', 'nhk.article.draft.update', 'nhk.article.publish', 'nhk.article.publish.review', 'nhk.article.publish.approve', 'nhk.article.trash', 'nhk.article.restore' => 'nhk_ingest_articles',
            'nhk.proposal.submit' => 'nhk_submit_proposals',
            'nhk.proposal.approve', 'nhk.proposal.reject' => 'nhk_approve_proposals',
            'nhk.proposal.eligibility' => 'nhk_view_governance',
            'nhk.proposal.apply' => 'nhk_apply_proposals',
            'nhk.public-url.audit', 'nhk.public-url.reproject' => 'nhk_manage_public_urls',
            default => 'nhk_create_proposals',
        };
        return !function_exists('current_user_can') || current_user_can($capability);
    }

    private static function execute(string $tool, McpReadHandler $read, mixed $input): mixed
    {
        $input = is_array($input) ? $input : [];
        try {
            return match ($tool) {
                'nhk.search' => $read->search((string) ($input['q'] ?? ''), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20)),
            'nhk.semantic.resolve' => $read->semanticResolve((array) ($input['context'] ?? [])),
                'nhk.article.preflight' => self::executeMcp($tool, $input),
                'nhk.category.resolve' => self::executeMcp($tool, $input),
                'nhk.entity.get' => $read->entityGet((string) ($input['type'] ?? ''), (string) ($input['id'] ?? '')),
                'nhk.media.get' => $read->mediaGet((string) ($input['id'] ?? '')),
                'nhk.video.get' => $read->videoGet((string) ($input['id'] ?? '')),
                'nhk.knowledge.get' => $read->knowledgeGet((string) ($input['id'] ?? '')),
                'nhk.source.get' => $read->sourceGet((string) ($input['id'] ?? '')),
            'nhk.evidence.get' => $read->evidenceGet((string) ($input['id'] ?? '')),
                'nhk.media.attachment.get' => $read->mediaAttachmentGet((int) ($input['attachment_id'] ?? 0)),
                default => new \WP_Error('nhk_mcp_ability_not_found', 'NHK V3 read ability is not registered.'),
            };
        } catch (\InvalidArgumentException $error) {
            return new \WP_Error('nhk_mcp_invalid_input', $error->getMessage(), ['status' => 400]);
        } catch (\Throwable) {
            return new \WP_Error('nhk_mcp_read_unavailable', 'NHK V3 read ability is unavailable.', ['status' => 503]);
        }
    }

    private static function canRead(string $tool): bool
    {
        $capability = in_array($tool, ['nhk.proposal.eligibility', 'nhk.proposal.review'], true) ? 'nhk_view_governance' : 'read';
        return !function_exists('current_user_can') || current_user_can($capability);
    }

    private static function label(string $tool): string
    {
        return [
            'nhk.public-url.audit' => 'NHK Public URL Audit',
            'nhk.public-url.reproject' => 'NHK Public URL Reproject',
            'nhk.search' => 'NHK Search',
            'nhk.semantic.resolve' => 'NHK Semantic Resolve',
            'nhk.entity.get' => 'NHK Entity Get',
            'nhk.media.get' => 'NHK Media Get',
            'nhk.video.get' => 'NHK Video Get',
            'nhk.knowledge.get' => 'NHK Knowledge Get',
            'nhk.source.get' => 'NHK Source Get',
            'nhk.evidence.get' => 'NHK Evidence Get',
            'nhk.article.preflight' => 'NHK Article Research / Preflight',
            'nhk.category.resolve' => 'NHK Category Search / Get',
            'nhk.media.attachment.get' => 'NHK Media Read-back',
            'nhk.article.ingest' => 'NHK Article Ingest / Create Draft',
            'nhk.category.create' => 'NHK Category Create',
            'nhk.category.update' => 'NHK Category Update',
            'nhk.category.assign' => 'NHK Category Assign',
            'nhk.category.unassign' => 'NHK Category Unassign',
            'nhk.category.delete' => 'NHK Category Guarded Delete',
            'nhk.article.draft.create' => 'NHK Article Create Draft',
            'nhk.article.draft.update' => 'NHK Article Update',
            'nhk.article.publish' => 'NHK Article Publish',
            'nhk.article.trash' => 'NHK Article Trash',
            'nhk.article.restore' => 'NHK Article Restore',
            'nhk.media.ingest' => 'NHK Image Intake / Upload Normalization',
            'nhk.knowledge.ingest' => 'NHK Knowledge Ingest',
            'nhk.source.ingest' => 'NHK Source Ingest',
            'nhk.evidence.ingest' => 'NHK Evidence Ingest',
            'nhk.video.ingest' => 'NHK Video Ingest',
            'nhk.proposal.create' => 'NHK Proposal Create',
            'nhk.proposal.submit' => 'NHK Proposal Submit',
            'nhk.proposal.approve' => 'NHK Proposal Approve',
            'nhk.proposal.reject' => 'NHK Proposal Reject',
            'nhk.proposal.eligibility' => 'NHK Proposal Eligibility',
            'nhk.proposal.apply' => 'NHK Proposal Apply',
            'nhk.proposal.review' => 'NHK Proposal Review',
        ][$tool] ?? 'NHK V3 Read';
    }
}
