<?php
declare(strict_types=1);

namespace NHK\Core\Application\Mcp;

final class McpAbilityRegistration
{
    private const CATEGORY = 'nhk-semantic';

    /** @var array<string,string> */
    private const READ_TOOL_MAP = [
        'nhk.search' => 'nhk-v3/search',
        'nhk.semantic.resolve' => 'nhk-v3/semantic-resolve',
        'nhk.entity.get' => 'nhk-v3/entity-get',
        'nhk.media.get' => 'nhk-v3/media-get',
        'nhk.video.get' => 'nhk-v3/video-get',
        'nhk.knowledge.get' => 'nhk-v3/knowledge-get',
        'nhk.source.get' => 'nhk-v3/source-get',
        'nhk.evidence.get' => 'nhk-v3/evidence-get',
    ];

    /** @return list<string> */
    public static function readAbilityNames(): array
    {
        return array_values(self::READ_TOOL_MAP);
    }

    public static function abilityNameForTool(string $tool): ?string
    {
        return self::READ_TOOL_MAP[$tool] ?? null;
    }

    public static function registerCategory(): void
    {
        if (!function_exists('wp_register_ability_category')) return;
        wp_register_ability_category(self::CATEGORY, [
            'label' => 'NHK Semantic',
            'description' => 'Read-only discovery abilities for the NHK V3 semantic runtime.',
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
                'permission_callback' => static fn (): bool => self::canRead(),
                'meta' => [
                    'public' => true,
                    'show_in_rest' => true,
                    'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
                ],
            ]);
        }
    }

    public static function registerArticleAbilities(McpArticleIngestHandler $article): void
    {
        if (!function_exists('wp_register_ability')) return;
        $tools = array_column(McpToolCatalog::tools(), null, 'name');
        foreach (['nhk.article.preflight', 'nhk.article.ingest'] as $toolName) {
            $tool = $tools[$toolName] ?? null;
            if (!is_array($tool)) continue;
            $ingest = $toolName === 'nhk.article.ingest';
            wp_register_ability($ingest ? 'nhk-v3/article-ingest' : 'nhk-v3/article-preflight', [
                'label' => $ingest ? 'NHK Article Ingest' : 'NHK Article Preflight',
                'description' => (string) $tool['description'],
                'category' => self::CATEGORY,
                'input_schema' => (array) $tool['inputSchema'],
                'output_schema' => ['type' => 'object'],
                'execute_callback' => static fn (mixed $input = null): mixed => self::executeArticle($article, $ingest, $input),
                'permission_callback' => static fn (): bool => $ingest ? self::canIngest() : self::canRead(),
                'meta' => [
                    'public' => true,
                    'show_in_rest' => true,
                    'annotations' => ['readonly' => !$ingest, 'destructive' => false, 'idempotent' => true],
                ],
            ]);
        }
    }

    private static function execute(string $tool, McpReadHandler $read, mixed $input): mixed
    {
        $input = is_array($input) ? $input : [];
        try {
            return match ($tool) {
                'nhk.search' => $read->search((string) ($input['q'] ?? ''), (int) ($input['page'] ?? 1), (int) ($input['per_page'] ?? 20)),
                'nhk.semantic.resolve' => $read->semanticResolve((array) ($input['context'] ?? [])),
                'nhk.entity.get' => $read->entityGet((string) ($input['type'] ?? ''), (string) ($input['id'] ?? '')),
                'nhk.media.get' => $read->mediaGet((string) ($input['id'] ?? '')),
                'nhk.video.get' => $read->videoGet((string) ($input['id'] ?? '')),
                'nhk.knowledge.get' => $read->knowledgeGet((string) ($input['id'] ?? '')),
                'nhk.source.get' => $read->sourceGet((string) ($input['id'] ?? '')),
                'nhk.evidence.get' => $read->evidenceGet((string) ($input['id'] ?? '')),
                default => new \WP_Error('nhk_mcp_ability_not_found', 'NHK V3 read ability is not registered.'),
            };
        } catch (\InvalidArgumentException $error) {
            return new \WP_Error('nhk_mcp_invalid_input', $error->getMessage(), ['status' => 400]);
        } catch (\Throwable) {
            return new \WP_Error('nhk_mcp_read_unavailable', 'NHK V3 read ability is unavailable.', ['status' => 503]);
        }
    }

    private static function canRead(): bool
    {
        return !function_exists('current_user_can') || current_user_can('read');
    }

    private static function canIngest(): bool
    {
        return !function_exists('current_user_can') || current_user_can('nhk_ingest_articles');
    }

    private static function executeArticle(McpArticleIngestHandler $article, bool $ingest, mixed $input): mixed
    {
        $input = is_array($input) ? $input : [];
        try {
            return $ingest ? $article->ingest($input) : $article->preflight($input);
        } catch (\InvalidArgumentException $error) {
            return new \WP_Error('nhk_mcp_invalid_input', $error->getMessage(), ['status' => 400]);
        } catch (\Throwable) {
            return new \WP_Error('nhk_mcp_article_unavailable', 'NHK Article operation is unavailable.', ['status' => 503]);
        }
    }

    private static function label(string $tool): string
    {
        return [
            'nhk.search' => 'NHK Search',
            'nhk.semantic.resolve' => 'NHK Semantic Resolve',
            'nhk.entity.get' => 'NHK Entity Get',
            'nhk.media.get' => 'NHK Media Get',
            'nhk.video.get' => 'NHK Video Get',
            'nhk.knowledge.get' => 'NHK Knowledge Get',
            'nhk.source.get' => 'NHK Source Get',
            'nhk.evidence.get' => 'NHK Evidence Get',
        ][$tool] ?? 'NHK V3 Read';
    }
}
