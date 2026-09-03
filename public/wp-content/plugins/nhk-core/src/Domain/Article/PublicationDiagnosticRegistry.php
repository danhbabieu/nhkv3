<?php
declare(strict_types=1);

namespace NHK\Core\Domain\Article;

final class PublicationDiagnosticRegistry
{
    public const POLICY_VERSION = 'owner-publication-v1';

    /** @var array<string,PublicationDiagnosticDefinition>|null */
    private static ?array $definitions = null;

    public static function policyVersion(): string { return self::POLICY_VERSION; }

    public static function definition(string $code): ?PublicationDiagnosticDefinition
    {
        self::boot();
        return self::$definitions[$code] ?? null;
    }

    /** @param list<string> $failedCodes */
    public static function classify(array $failedCodes): ArticlePublicationOutcome
    {
        self::boot();
        foreach (array_unique($failedCodes) as $code) {
            $definition = self::$definitions[$code] ?? null;
            if ($definition === null || $definition->classification === ArticlePublicationOutcome::SYSTEM_BLOCKED) return ArticlePublicationOutcome::SYSTEM_BLOCKED;
        }
        foreach (array_unique($failedCodes) as $code) if (self::$definitions[$code]->classification === ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED) return ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED;
        return ArticlePublicationOutcome::PASS;
    }

    /** @param list<string> $failedCodes */
    public static function fingerprint(array $failedCodes): string
    {
        $codes = array_values(array_unique(array_map('strval', $failedCodes)));
        sort($codes, SORT_STRING);
        return hash('sha256', self::POLICY_VERSION . ':' . implode('|', $codes));
    }

    private static function boot(): void
    {
        if (self::$definitions !== null) return;
        $owner = static fn (string $code, string $message, string $hint): PublicationDiagnosticDefinition => new PublicationDiagnosticDefinition($code, ArticlePublicationOutcome::OWNER_REVIEW_REQUIRED, $message, $hint, self::POLICY_VERSION);
        $system = static fn (string $code, string $message, string $hint): PublicationDiagnosticDefinition => new PublicationDiagnosticDefinition($code, ArticlePublicationOutcome::SYSTEM_BLOCKED, $message, $hint, self::POLICY_VERSION);
        self::$definitions = [];
        foreach ([
            $owner('REAL_IMAGE_INCOMPLETE', 'Ảnh thật chưa hoàn tất.', 'Bổ sung ảnh thật phù hợp.'),
            $owner('MEDIAUSAGE_INCOMPLETE', 'Thông tin sử dụng Media chưa hoàn tất.', 'Hoàn tất MediaUsage.'),
            $owner('SEO_PROJECTION_INVALID', 'SEO chưa hoàn tất.', 'Hoàn tất projection SEO.'),
            $owner('INTERNAL_LINKS_INCOMPLETE', 'Liên kết nội bộ chưa hoàn tất.', 'Bổ sung liên kết nội bộ hợp lệ.'),
            $owner('STRUCTURED_DATA_INCOMPLETE', 'Structured data chưa hoàn tất.', 'Hoàn tất dữ liệu có cấu trúc.'),
            $owner('SEMANTIC_READBACK_UNVERIFIED', 'Semantic read-back chưa hoàn tất.', 'Xác minh lại semantic read-back.'),
            $owner('KNOWLEDGE_EVIDENCE_INCOMPLETE', 'Knowledge/Evidence chưa hoàn tất.', 'Bổ sung kiểm chứng trong phạm vi.'),
            $owner('FAQ_INCOMPLETE', 'FAQ chưa hoàn tất.', 'Hoàn tất FAQ theo contract.'),
            $system('EDITORIAL_POST_NOT_DRAFT', 'Post không còn ở trạng thái draft.', 'Đọc lại trạng thái Post.'),
            $system('EDITORIAL_CAS_REQUIRED', 'Thiếu hoặc sai editorial state token.', 'Đọc lại Post và state token.'),
            $system('CANONICAL_PUBLIC_IDENTITY_INVALID', 'Không xác định được public identity hợp lệ.', 'Sửa identity/route qua contract.'),
            $system('RESEARCH_PREFLIGHT_BLOCKED', 'Research preflight bị chặn.', 'Hoàn tất research preflight.'),
            $system('SUBJECT_UNRESOLVED', 'Không xác định được subject duy nhất.', 'Giải quyết identity ambiguity.'),
            $system('DUPLICATE_INTENT_UNRESOLVED', 'Duplicate intent chưa được giải quyết.', 'Giải quyết intent qua Governance.'),
            $system('CATEGORY_UNRESOLVED', 'Category không xác định được.', 'Giải quyết category native.'),
            $system('SEMANTIC_PLAN_INCOMPLETE', 'Semantic plan chưa hoàn tất.', 'Hoàn tất semantic Governance.'),
            $system('PUBLIC_CLAIM_COMPLIANCE_BLOCKED', 'Compliance claim chưa được xác minh.', 'Bổ sung support hợp lệ hoặc thu hẹp claim.'),
            $system('INTERNAL_LINKS_INVALID', 'Liên kết nội bộ không hợp lệ.', 'Sửa liên kết và chạy read-back.'),
            $system('STRUCTURED_DATA_INVALID', 'Structured data không hợp lệ.', 'Sửa structured data.'),
            $system('PUBLIC_ROUTE_NOT_READY', 'Public route chưa sẵn sàng.', 'Giải quyết route/identity conflict.'),
            $system('RENDERED_PUBLIC_VERIFICATION_UNAVAILABLE', 'Không xác minh được rendered public output.', 'Khôi phục verification runtime.'),
        ] as $definition) self::$definitions[$definition->code] = $definition;
    }
}
