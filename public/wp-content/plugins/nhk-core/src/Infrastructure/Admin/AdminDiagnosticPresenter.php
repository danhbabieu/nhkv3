<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Article\ArticlePublicationOutcome;
use NHK\Core\Domain\Article\PublicationDiagnosticRegistry;

/**
 * Maps existing diagnostic codes to operator-facing presentation only.
 */
final class AdminDiagnosticPresenter
{
    /**
     * @param array<string,scalar|null> $context
     * @return array{code:string,severity:string,title:string,message:string,remediation:string,overridable:bool}
     */
    public static function present(string $code, array $context = []): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') $code = 'UNKNOWN_DIAGNOSTIC';

        $publication = PublicationDiagnosticRegistry::definition($code);
        if ($publication !== null) {
            $overridable = $publication->classification === ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED;
            return [
                'code' => $publication->code,
                'severity' => strtolower($publication->classification->value),
                'title' => $overridable ? 'Cần chủ sở hữu xem xét' : 'Hệ thống đang chặn',
                'message' => self::interpolate($publication->ownerMessage, $context),
                'remediation' => self::interpolate($publication->remediationHint, $context),
                'overridable' => $overridable,
            ];
        }

        $definition = self::definitions()[$code] ?? null;
        if ($definition === null) {
            return [
                'code' => $code,
                'severity' => 'system_blocked',
                'title' => 'Chẩn đoán chưa được đăng ký',
                'message' => 'Hệ thống không nhận diện được mã chẩn đoán ' . $code . '.',
                'remediation' => 'Dừng thao tác và kiểm tra registry hoặc runtime trước khi tiếp tục.',
                'overridable' => false,
            ];
        }

        return [
            'code' => $code,
            'severity' => $definition['severity'],
            'title' => self::interpolate($definition['title'], $context),
            'message' => self::interpolate($definition['message'], $context),
            'remediation' => self::interpolate($definition['remediation'], $context),
            'overridable' => $definition['overridable'],
        ];
    }

    /** @return array{code:string,severity:string,title:string,message:string,remediation:string,overridable:bool} */
    public static function forProposalState(string $state): array
    {
        $code = match (strtolower(trim($state))) {
            'approved' => 'ELIGIBILITY_CHECK_REQUIRED',
            'applied' => 'ALREADY_APPLIED',
            'submitted' => 'APPROVAL_MISSING',
            'draft', 'rejected', 'failed' => 'NOT_APPROVED',
            default => 'UNREGISTERED_PROPOSAL_STATE',
        };

        return self::present($code, ['state' => $state]);
    }

    /**
     * @return array<string,array{severity:string,title:string,message:string,remediation:string,overridable:bool}>
     */
    private static function definitions(): array
    {
        $system = static fn (string $title, string $message, string $remediation): array => [
            'severity' => 'system_blocked',
            'title' => $title,
            'message' => $message,
            'remediation' => $remediation,
            'overridable' => false,
        ];

        return [
            'DATABASE_UNREACHABLE' => $system('Không kết nối được cơ sở dữ liệu', 'Database runtime không sẵn sàng.', 'Khôi phục kết nối database rồi chạy lại health check.'),
            'MIGRATION_REQUIRED' => $system('Cần cập nhật schema', 'Migration hiện tại chưa đạt phiên bản mục tiêu.', 'Kiểm tra migration ledger và chỉ chạy UP migration được phép.'),
            'COMPOSER_AUTOLOAD_MISSING' => $system('Thiếu Composer autoload', 'Runtime không tải được Composer autoload.', 'Khôi phục dependency release và chạy lại deployment preflight.'),
            'SYMFONY_UID_UNAVAILABLE' => $system('Thiếu thư viện UUID', 'Runtime UUID bắt buộc không khả dụng.', 'Khôi phục dependency đã khóa và chạy lại health check.'),
            'NHK_RUNTIME_CLASS_UNAVAILABLE' => $system('Runtime NHK chưa đầy đủ', 'Một lớp runtime bắt buộc không khả dụng.', 'Kiểm tra gói release, autoload và bootstrap.'),
            'REST_BOOTSTRAP_UNAVAILABLE' => $system('REST bootstrap không khả dụng', 'WordPress REST runtime chưa sẵn sàng.', 'Khôi phục REST bootstrap trước khi dùng thao tác Admin.'),
            'HYDRATION_LOSS' => $system('Hydration làm mất dữ liệu', 'Bản ghi không thể hydrate đầy đủ theo contract.', 'Dừng thao tác và kiểm tra schema, hydrator cùng dữ liệu nguồn.'),
            'HYDRATION_RUNTIME_FAILURE' => $system('Hydration runtime thất bại', 'Không thể xác minh hydration do lỗi runtime.', 'Khôi phục runtime và chạy lại health check.'),
            'ELIGIBILITY_CHECK_REQUIRED' => [
                'severity' => 'uncertain',
                'title' => 'Cần kiểm tra eligibility',
                'message' => 'Proposal đã được duyệt nhưng eligibility chưa được đọc lại.',
                'remediation' => 'Chạy Eligibility để lấy kết quả và reason code hiện tại.',
                'overridable' => false,
            ],
            'ALREADY_APPLIED' => [
                'severity' => 'success',
                'title' => 'Đã Controlled Apply',
                'message' => 'Proposal đã ở trạng thái applied.',
                'remediation' => 'Đọc lại canonical owner và audit trước khi kết luận hoàn tất.',
                'overridable' => false,
            ],
            'APPROVAL_MISSING' => [
                'severity' => 'blocked',
                'title' => 'Chưa có phê duyệt',
                'message' => 'Proposal chưa có approval hợp lệ.',
                'remediation' => 'Hoàn tất review và approval theo Governance.',
                'overridable' => false,
            ],
            'NOT_APPROVED' => [
                'severity' => 'blocked',
                'title' => 'Chưa đủ điều kiện kiểm tra',
                'message' => 'Proposal hiện không ở trạng thái approved.',
                'remediation' => 'Kiểm tra lifecycle và hoàn tất bước Governance phù hợp.',
                'overridable' => false,
            ],
        ];
    }

    /** @param array<string,scalar|null> $context */
    private static function interpolate(string $text, array $context): string
    {
        foreach ($context as $key => $value) {
            $text = str_replace('{' . $key . '}', (string) $value, $text);
        }
        return $text;
    }
}
