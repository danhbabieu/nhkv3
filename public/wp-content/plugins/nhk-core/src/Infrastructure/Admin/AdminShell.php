<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

/**
 * Presentation-only shell for NHK V3 Admin workspaces.
 */
final class AdminShell
{
    /** @var array<string,string> */
    private const GOVERNANCE_ACTION_CAPABILITIES = [
        'create' => 'nhk_create_proposals',
        'submit' => 'nhk_submit_proposals',
        'approve' => 'nhk_approve_proposals',
        'apply' => 'nhk_apply_proposals',
    ];

    /**
     * @param array<string,bool> $capabilities
     * @return array<string,array{slug:string,label:string,description:string,read_capability:string,write_capability:list<string>,available:bool,reason:string,mode:string,actions:array<string,array{capability:string,allowed:bool}>}>
     */
    public static function workspaceDefinitions(array $capabilities): array
    {
        $definitions = [
            'overview' => [
                'slug' => 'overview',
                'label' => 'Tổng quan',
                'description' => 'Health runtime, khả năng hiện có và trạng thái môi trường trung thực.',
                'read_capability' => 'manage_options',
                'write_capability' => [],
                'implemented' => true,
            ],
            'governance' => [
                'slug' => 'governance',
                'label' => 'Governance',
                'description' => 'Theo dõi Proposal, eligibility, Controlled Apply và kết quả đọc lại.',
                'read_capability' => 'nhk_view_governance',
                'write_capability' => array_values(self::GOVERNANCE_ACTION_CAPABILITIES),
                'implemented' => true,
            ],
            'editorial' => [
                'slug' => 'editorial',
                'label' => 'Biên tập',
                'description' => 'Biên tập Article native và chẩn đoán publication gate.',
                'read_capability' => 'edit_posts',
                'write_capability' => ['nhk_ingest_articles'],
                'implemented' => false,
            ],
            'semantic' => [
                'slug' => 'semantic',
                'label' => 'Semantic',
                'description' => 'Tra cứu canonical entity, Media, Video, Knowledge, Source, Evidence và Graph.',
                'read_capability' => 'manage_options',
                'write_capability' => ['nhk_create_proposals'],
                'implemented' => true,
            ],
            'media-video' => [
                'slug' => 'media-video',
                'label' => 'Media & Video',
                'description' => 'Media ingest, MediaAsset, MediaUsage và Video intake theo boundary hiện có.',
                'read_capability' => 'upload_files',
                'write_capability' => ['nhk_create_proposals'],
                'implemented' => false,
            ],
            'operations' => [
                'slug' => 'operations',
                'label' => 'Vận hành',
                'description' => 'Migration ledger, audit/read-back và chẩn đoán hạ tầng.',
                'read_capability' => 'manage_options',
                'write_capability' => [],
                'implemented' => true,
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $canRead = ($capabilities[$definition['read_capability']] ?? false) === true;
            $implemented = $definition['implemented'] === true;
            $actions = [];
            foreach ($definition['write_capability'] as $capability) {
                $action = array_search($capability, self::GOVERNANCE_ACTION_CAPABILITIES, true);
                $actions[is_string($action) ? $action : $capability] = [
                    'capability' => $capability,
                    'allowed' => ($capabilities[$capability] ?? false) === true,
                ];
            }
            [$mode, $reason] = self::workspaceMode(
                $implemented,
                $canRead,
                $definition['read_capability'],
                $actions
            );

            unset($definition['implemented']);
            $definitions[$key] = $definition + [
                'available' => $implemented && $canRead,
                'reason' => $reason,
                'mode' => $mode,
                'actions' => $actions,
            ];
        }

        return $definitions;
    }

    /** @return list<string> */
    public static function capabilityNames(): array
    {
        $names = ['manage_options', 'edit_posts', 'upload_files', 'nhk_view_governance', 'nhk_ingest_articles'];
        foreach (self::GOVERNANCE_ACTION_CAPABILITIES as $capability) $names[] = $capability;
        return array_values(array_unique($names));
    }

    public static function isNhkScreen(string $hookSuffix, string $page): bool
    {
        return str_contains($hookSuffix, 'nhk-v3') || $page === 'nhk-v3' || str_starts_with($page, 'nhk-v3-');
    }

    /**
     * @param array<string,array<string,mixed>> $workspaceDefinitions
     */
    public static function render(string $activeWorkspace, array $workspaceDefinitions, callable $content): void
    {
        $active = $workspaceDefinitions[$activeWorkspace] ?? null;
        if (!is_array($active)) {
            $activeWorkspace = (string) array_key_first($workspaceDefinitions);
            $active = $workspaceDefinitions[$activeWorkspace] ?? [];
        }

        $activeLabel = (string) ($active['label'] ?? 'NHK V3');
        $activeDescription = (string) ($active['description'] ?? 'Không gian quản trị và chẩn đoán NHK V3.');
        $activeMode = (string) ($active['mode'] ?? 'unavailable');
        $activeReason = (string) ($active['reason'] ?? '');

        echo '<div class="wrap nhk-admin-shell" data-nhk-admin-shell>';
        echo '<a class="nhk-admin-skip-link" href="#nhk-admin-main">Bỏ qua đến nội dung chính</a>';
        echo '<nav class="nhk-admin-breadcrumbs" aria-label="Đường dẫn"><ol><li>NHK V3</li><li aria-current="page">' . self::escape($activeLabel) . '</li></ol></nav>';
        echo '<header class="nhk-admin-shell__header"><p class="nhk-admin-shell__eyebrow">NHK V3 · Admin</p><h1>' . self::escape($activeLabel) . '</h1><p>' . self::escape($activeDescription) . '</p></header>';
        echo '<nav class="nhk-admin-workspaces" aria-label="Không gian làm việc NHK V3"><ul>';

        foreach ($workspaceDefinitions as $key => $definition) {
            if (!is_array($definition)) continue;
            $slug = self::slug((string) ($definition['slug'] ?? $key));
            $label = (string) ($definition['label'] ?? $key);
            $mode = (string) ($definition['mode'] ?? 'unavailable');
            $available = array_key_exists('available', $definition)
                ? $definition['available'] === true
                : $mode !== 'unavailable';
            $modeLabel = match ($mode) {
                'full' => 'Đầy đủ',
                'limited' => 'Giới hạn',
                'read_only' => 'Chỉ đọc',
                default => 'Không khả dụng',
            };

            echo '<li>';
            if ($available) {
                $current = (string) $key === $activeWorkspace;
                echo '<a href="#nhk-workspace-' . self::escape($slug) . '" data-workspace="' . self::escape($slug) . '"' . ($current ? ' aria-current="page"' : '') . '>';
                echo '<span>' . self::escape($label) . '</span><small>' . self::escape($modeLabel) . ($current ? ' · Đang mở' : '') . '</small></a>';
            } else {
                echo '<span class="nhk-admin-workspaces__unavailable" aria-disabled="true"><span>' . self::escape($label) . '</span><small>' . self::escape($modeLabel) . '</small></span>';
            }
            echo '</li>';
        }

        echo '</ul></nav>';
        echo '<main id="nhk-admin-main" class="nhk-admin-shell__main" tabindex="-1">';
        if ($activeMode === 'read_only' && $activeReason !== '') {
            echo '<p class="notice notice-info nhk-admin-read-only"><strong>Chế độ chỉ đọc.</strong> ' . self::escape($activeReason) . '</p>';
        } elseif ($activeMode === 'limited' && $activeReason !== '') {
            echo '<p class="notice notice-warning nhk-admin-limited"><strong>Quyền thao tác giới hạn.</strong> ' . self::escape($activeReason) . '</p>';
        } elseif ($activeMode === 'unavailable' && $activeReason !== '') {
            echo '<p class="notice notice-warning"><strong>Không khả dụng.</strong> ' . self::escape($activeReason) . '</p>';
        }
        $content();
        echo '</main></div>';
    }

    public static function renderWorkspaceRegion(string $slug, string $label, callable $content): void
    {
        $slug = self::slug($slug);
        echo '<section id="nhk-workspace-' . self::escape($slug) . '" class="nhk-admin-workspace-region" aria-labelledby="nhk-workspace-' . self::escape($slug) . '-heading">';
        echo '<h2 id="nhk-workspace-' . self::escape($slug) . '-heading">' . self::escape($label) . '</h2>';
        $content();
        echo '</section>';
    }

    /**
     * @param array<string,array{capability:string,allowed:bool}> $actions
     * @return array{string,string}
     */
    private static function workspaceMode(bool $implemented, bool $canRead, string $readCapability, array $actions): array
    {
        if (!$implemented) return ['unavailable', 'Không gian này chưa được triển khai trong lát cắt hiện tại.'];
        if (!$canRead) return ['unavailable', 'Thiếu quyền đọc ' . $readCapability . '.'];
        if ($actions === []) return ['read_only', 'Không gian này chỉ cung cấp dữ liệu và chẩn đoán read-only.'];

        $missing = [];
        $allowed = 0;
        foreach ($actions as $action) {
            if ($action['allowed']) {
                $allowed++;
            } else {
                $missing[] = $action['capability'];
            }
        }
        if ($missing === []) return ['full', ''];

        $reason = 'Thiếu quyền thao tác: ' . implode(', ', $missing) . '.';
        return [$allowed === 0 ? 'read_only' : 'limited', $reason];
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($value));
        return trim((string) $slug, '-') ?: 'workspace';
    }
}
