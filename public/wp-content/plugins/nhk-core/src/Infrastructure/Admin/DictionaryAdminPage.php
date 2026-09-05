<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Application\Dictionary\DictionaryRuntime;
use NHK\Core\Domain\Dictionary\{DictionaryCandidateState, DictionaryConcept, DictionaryLabel};

final class DictionaryAdminPage
{
    private static ?DictionaryRuntime $runtime = null;

    public static function register(DictionaryRuntime $runtime): void
    {
        self::$runtime = $runtime;
        add_action('admin_menu', static function (): void { add_submenu_page('nhk-v3', 'Từ điển', 'Từ điển', 'nhk_curate_dictionary', 'nhk-v3-dictionary', [self::class, 'render']); }, 20);
        add_action('admin_post_nhk_dictionary_decide', [self::class, 'decide']);
        add_action('admin_post_nhk_dictionary_draft', [self::class, 'draft']);
        add_action('admin_post_nhk_dictionary_attach', [self::class, 'attach']);
        add_action('admin_post_nhk_dictionary_approve', [self::class, 'approve']);
    }

    public static function render(): void
    {
        self::authorize();
        $runtime = self::$runtime;
        echo '<div class="wrap"><h1>Từ điển — hộp thư duyệt</h1>';
        if (!$runtime || !$runtime->available()) { echo '<div class="notice notice-warning"><p>Dictionary storage chưa sẵn sàng. Không hiển thị kết quả rỗng giả.</p></div></div>'; return; }
        echo '<p>Candidate tự động chỉ là gợi ý; không tự tạo Knowledge, Evidence hay Graph relation.</p>';
        self::renderDrafts($runtime);
        self::renderCandidates($runtime);
        echo '</div>';
    }

    private static function renderDrafts(DictionaryRuntime $runtime): void
    {
        $drafts = $runtime->concepts()->listByStatus(DictionaryConcept::DRAFT, 200);
        echo '<h2>Mục từ nháp</h2>';
        if ($drafts === []) { echo '<p>Không có mục nháp.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Tên</th><th>Định nghĩa</th><th>Revision</th><th>Canonical destination</th><th>Thao tác</th></tr></thead><tbody>';
        foreach ($drafts as $concept) {
            echo '<tr><td><strong>' . esc_html($concept->preferredLabel) . '</strong><br><code>' . esc_html($concept->conceptId) . '</code></td><td>' . esc_html($concept->definition) . '</td><td>' . esc_html((string) $concept->revision) . '</td><td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; self::nonce();
            echo '<input type="hidden" name="action" value="nhk_dictionary_approve"><input type="hidden" name="concept_id" value="' . esc_attr($concept->conceptId) . '"><input type="hidden" name="revision" value="' . esc_attr((string) $concept->revision) . '">';
            echo '<p><input name="public_slug" value="' . esc_attr((string) ($concept->context['public_slug'] ?? '')) . '" placeholder="slug riêng"></p>';
            echo '<p><input name="destination_type" value="' . esc_attr((string) ($concept->destinationType ?? '')) . '" placeholder="owner type"></p><p><input name="destination_id" value="' . esc_attr((string) ($concept->destinationId ?? '')) . '" placeholder="owner id" size="38"></p><p><input name="destination_url" value="' . esc_attr((string) ($concept->destinationUrl ?? '')) . '" placeholder="/canonical/url/" size="38"></p>';
            echo '</td><td><button class="button button-primary">Duyệt mục từ</button></form></td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderCandidates(DictionaryRuntime $runtime): void
    {
        $items = $runtime->candidates()->listForReview(200);
        echo '<h2>Candidate cần xem</h2>';
        if ($items === []) { echo '<p>Không có candidate cần duyệt.</p>'; return; }
        echo '<table class="widefat striped"><thead><tr><th>Thuật ngữ</th><th>Nguồn/ngữ cảnh</th><th>Lần gặp</th><th>Gợi ý</th><th>Thao tác</th></tr></thead><tbody>';
        foreach ($items as $candidate) {
            $raw = implode(', ', array_map('strval', $candidate->rawForms));
            $context = (string) wp_json_encode($candidate->context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo '<tr><td><strong>' . esc_html($raw ?: $candidate->normalizedTerm) . '</strong><br><code>' . esc_html($candidate->normalizedTerm) . '</code></td><td><small>' . esc_html($context) . '</small></td><td>' . esc_html((string) $candidate->occurrences) . '</td><td>Cần xác định: alias của mục đã có hay khái niệm độc lập.</td><td>';
            self::decisionForm($candidate->candidateId, $candidate->revision, DictionaryCandidateState::IGNORED, 'Bỏ qua');
            self::decisionForm($candidate->candidateId, $candidate->revision, DictionaryCandidateState::DO_NOT_SUGGEST, 'Không gợi ý lại');
            echo '<details><summary>Tạo mục nháp</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; self::nonce();
            echo '<input type="hidden" name="action" value="nhk_dictionary_draft"><input type="hidden" name="candidate_id" value="' . esc_attr($candidate->candidateId) . '"><input type="hidden" name="revision" value="' . esc_attr((string) $candidate->revision) . '"><p><input name="preferred_label" value="' . esc_attr((string) ($candidate->rawForms[0] ?? $candidate->normalizedTerm)) . '" required></p><p><textarea name="definition" rows="3" placeholder="Định nghĩa ngắn"></textarea></p><p><input name="public_slug" placeholder="slug"></p><p><select name="term_type"><option>GENERAL</option><option>TECHNICAL</option><option>COLLOQUIAL</option><option>PHONETIC</option><option>HISTORICAL</option><option>MARKET</option></select></p><button class="button">Tạo draft</button></form></details>';
            echo '<details><summary>Gắn mục có sẵn</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'; self::nonce();
            echo '<input type="hidden" name="action" value="nhk_dictionary_attach"><input type="hidden" name="candidate_id" value="' . esc_attr($candidate->candidateId) . '"><input type="hidden" name="revision" value="' . esc_attr((string) $candidate->revision) . '"><p><input name="concept_id" placeholder="Concept UUID" required size="38"></p><p><select name="label_kind"><option value="ALTERNATE">Alias</option><option value="COLLOQUIAL">Dân gian</option><option value="TECHNICAL">Kỹ thuật</option><option value="PHONETIC">Phiên âm</option></select></p><button class="button">Gắn</button></form></details>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public static function decide(): void
    {
        self::authorize(); self::verifyNonce();
        self::$runtime?->curation()->decide(self::candidateId(), self::revision(), strtoupper(sanitize_key((string) ($_POST['state'] ?? ''))));
        self::redirect();
    }

    public static function draft(): void
    {
        self::authorize(); self::verifyNonce();
        self::$runtime?->curation()->createDraftFromCandidate(self::candidateId(), self::revision(), sanitize_text_field((string) ($_POST['preferred_label'] ?? '')), sanitize_textarea_field((string) ($_POST['definition'] ?? '')), ['public_slug' => sanitize_title((string) ($_POST['public_slug'] ?? '')), 'term_type' => strtoupper(sanitize_key((string) ($_POST['term_type'] ?? 'GENERAL')))]);
        self::$runtime?->invalidateLabelCache(); self::redirect();
    }

    public static function attach(): void
    {
        self::authorize(); self::verifyNonce();
        self::$runtime?->curation()->attachToExisting(self::candidateId(), self::revision(), sanitize_text_field((string) ($_POST['concept_id'] ?? '')), strtoupper(sanitize_key((string) ($_POST['label_kind'] ?? DictionaryLabel::ALTERNATE))), 'vi-VN');
        self::$runtime?->invalidateLabelCache(); self::redirect();
    }

    public static function approve(): void
    {
        self::authorize(); self::verifyNonce();
        $runtime = self::$runtime; if (!$runtime) wp_die('Dictionary runtime unavailable.');
        $conceptId = sanitize_text_field((string) ($_POST['concept_id'] ?? ''));
        $current = $runtime->concepts()->findById($conceptId); if (!$current) wp_die('Dictionary concept not found.');
        $slug = sanitize_title((string) ($_POST['public_slug'] ?? ($current->context['public_slug'] ?? '')));
        $runtime->curation()->approveConcept($conceptId, self::revision(), self::nullable('destination_type'), self::nullable('destination_id'), self::nullable('destination_url'), ['public_slug' => $slug]);
        $runtime->invalidateLabelCache(); self::redirect();
    }

    private static function decisionForm(string $id, int $revision, string $state, string $label): void
    {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0 4px 4px 0">'; self::nonce();
        echo '<input type="hidden" name="action" value="nhk_dictionary_decide"><input type="hidden" name="candidate_id" value="' . esc_attr($id) . '"><input type="hidden" name="revision" value="' . esc_attr((string) $revision) . '"><input type="hidden" name="state" value="' . esc_attr($state) . '"><button class="button">' . esc_html($label) . '</button></form>';
    }

    private static function nonce(): void { wp_nonce_field('nhk_dictionary_curate', 'nhk_dictionary_nonce'); }
    private static function verifyNonce(): void { check_admin_referer('nhk_dictionary_curate', 'nhk_dictionary_nonce'); }
    private static function authorize(): void { if (!current_user_can('nhk_curate_dictionary')) wp_die('Dictionary curation capability required.'); }
    private static function candidateId(): string { return sanitize_text_field((string) ($_POST['candidate_id'] ?? '')); }
    private static function revision(): int { return max(1, (int) ($_POST['revision'] ?? 0)); }
    private static function nullable(string $key): ?string { $value = trim(sanitize_text_field((string) ($_POST[$key] ?? ''))); return $value === '' ? null : $value; }
    private static function redirect(): void { wp_safe_redirect(admin_url('admin.php?page=nhk-v3-dictionary')); exit; }
}
