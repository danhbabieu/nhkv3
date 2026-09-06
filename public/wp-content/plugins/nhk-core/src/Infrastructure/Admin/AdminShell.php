<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

/**
 * Presentation-only shell for NHK V3 Admin workspaces.
 */
final class AdminShell
{
    /**
     * @param array<string,bool> $capabilities
     * @return array<string,array{slug:string,label:string,description:string,read_capability:string,write_capability:string,available:bool,reason:string,mode:string}>
     */
    public static function workspaceDefinitions(array $capabilities): array
    {
        $definitions = [
            'governance' => [
                'slug' => 'governance',
                'label' => 'Duyệt thay đổi',
                'description' => 'Theo dõi Proposal, eligibility, Controlled Apply và kết quả đọc lại.',
                'read_capability' => 'manage_options',
                'write_capability' => 'nhk_apply_proposals',
            ],
            'semantic' => [
                'slug' => 'semantic',
                'label' => 'Tra cứu semantic',
                'description' => 'Tra cứu canonical entity, Media, Video, Knowledge, Source, Evidence và Graph.',
                'read_capability' => 'manage_options',
                'write_capability' => 'nhk_create_proposals',
            ],
            'system' => [
                'slug' => 'system',
                'label' => 'Hệ thống',
                'description' => 'Xem health, trạng thái migration và chẩn đoán hạ tầng.',
                'read_capability' => 'manage_options',
                'write_capability' => 'manage_options',
            ],
        ];

        foreach ($definitions as $key => $definition) {
            $canRead = ($capabilities[$definition['read_capability']] ?? false) === true;
            $canWrite = ($capabilities[$definition['write_capability']] ?? false) === true;
            $mode = !$canRead ? 'unavailable' : ($canWrite ? 'full' : 'read_only');
            $reason = match ($mode) {
                'unavailable' => 'Bạn chưa có quyền xem không gian làm việc này.',
                'read_only' => 'Bạn có quyền xem nhưng chưa có quyền thực hiện thay đổi.',
                default => '',
            };

            $definitions[$key] = $definition + [
                'available' => $canRead,
                'reason' => $reason,
                'mode' => $mode,
            ];
        }

        return $definitions;
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
        } elseif ($activeMode === 'unavailable' && $activeReason !== '') {
            echo '<p class="notice notice-warning"><strong>Không khả dụng.</strong> ' . self::escape($activeReason) . '</p>';
        }
        $content();
        echo '</main></div>';
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
