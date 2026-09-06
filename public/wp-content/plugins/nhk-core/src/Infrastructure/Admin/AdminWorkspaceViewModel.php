<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

/**
 * Produces presentation-only state without querying or mutating domain data.
 */
final class AdminWorkspaceViewModel
{
    private const STATES = ['ready', 'empty', 'unavailable', 'blocked', 'conflict', 'uncertain', 'success'];

    /**
     * @param array<string,mixed> $health
     * @param array<string,mixed> $capabilities
     * @param array<string,mixed> $counts
     * @return array{health:array<string,array<string,mixed>>,capabilities:array<string,array<string,mixed>>,counts:array<string,array<string,mixed>>}
     */
    public static function fromHealth(array $health, array $capabilities, array $counts): array
    {
        return [
            'health' => self::project($health, 'health'),
            'capabilities' => self::project($capabilities, 'capability'),
            'counts' => self::project($counts, 'count'),
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,array<string,mixed>>
     */
    private static function project(array $values, string $kind): array
    {
        $projected = [];
        foreach ($values as $key => $value) {
            $key = (string) $key;
            $reasonCode = self::reasonCode($value);
            $state = self::state($key, $value, $kind);
            $displayValue = self::displayValue($value);
            $projected[$key] = [
                'label' => self::label($key),
                'state' => $state,
                'state_label' => self::stateLabel($state),
                'value' => $displayValue,
                'display' => self::display($displayValue),
                'reason_code' => $reasonCode,
                'diagnostic' => $reasonCode !== null ? AdminDiagnosticPresenter::present($reasonCode) : null,
            ];
        }
        return $projected;
    }

    private static function state(string $key, mixed $value, string $kind): string
    {
        if ($value === null) return 'unavailable';

        if (is_array($value)) {
            $explicit = strtolower(trim((string) ($value['state'] ?? '')));
            if (in_array($explicit, self::STATES, true)) return $explicit;
            if (array_key_exists('ok', $value) && is_bool($value['ok'])) return $value['ok'] ? 'ready' : 'blocked';
            if (array_key_exists('configured', $value) && is_bool($value['configured'])) return $value['configured'] ? 'ready' : 'unavailable';
            $status = self::statusState((string) ($value['status'] ?? ''));
            if ($status !== null) return $status;
            return $value === [] ? 'empty' : 'ready';
        }

        if ($kind === 'count') {
            if (!is_int($value) && !is_float($value) && !is_numeric($value)) return 'uncertain';
            $count = (float) $value;
            if ($count < 0) return 'conflict';
            return $count === 0.0 ? 'empty' : 'success';
        }

        if (is_bool($value)) {
            if ($kind === 'health' && str_ends_with($key, '_required')) return $value ? 'blocked' : 'ready';
            return $value ? 'ready' : 'blocked';
        }

        return self::statusState(is_string($value) ? $value : '') ?? 'ready';
    }

    private static function statusState(string $status): ?string
    {
        return match (strtoupper(trim($status))) {
            'READY' => 'ready',
            'EMPTY' => 'empty',
            'UNAVAILABLE' => 'unavailable',
            'BLOCKED', 'SYSTEM_BLOCKED', 'ENVIRONMENT_BLOCKED' => 'blocked',
            'CONFLICT' => 'conflict',
            'UNCERTAIN' => 'uncertain',
            'SUCCESS', 'PASS', 'COMPLETED' => 'success',
            default => null,
        };
    }

    private static function displayValue(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_key_exists('ok', $value)) return $value['ok'];
        if (array_key_exists('configured', $value)) return $value['configured'];
        return $value;
    }

    private static function display(mixed $value): string
    {
        if ($value === null) return 'Không khả dụng';
        if (is_bool($value)) return $value ? 'Có' : 'Không';
        if (is_array($value)) return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return (string) $value;
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            'ready' => 'Sẵn sàng',
            'empty' => 'Trống',
            'blocked' => 'Bị chặn',
            'conflict' => 'Xung đột',
            'uncertain' => 'Chưa chắc chắn',
            'success' => 'Thành công',
            default => 'Không khả dụng',
        };
    }

    private static function reasonCode(mixed $value): ?string
    {
        if (!is_array($value)) return null;
        $reason = strtoupper(trim((string) ($value['reason_code'] ?? '')));
        return $reason !== '' ? $reason : null;
    }

    private static function label(string $key): string
    {
        return [
            'database' => 'Cơ sở dữ liệu',
            'database_reachable' => 'Kết nối cơ sở dữ liệu',
            'migration_current' => 'Migration hiện tại',
            'migration_target' => 'Migration mục tiêu',
            'migration_required' => 'Yêu cầu migration',
            'plugin_version' => 'Phiên bản plugin',
            'api_version' => 'Phiên bản API',
            'youtube_api' => 'YouTube API',
            'layers' => 'Các lớp runtime',
        ][$key] ?? ucfirst(str_replace('_', ' ', $key));
    }
}
