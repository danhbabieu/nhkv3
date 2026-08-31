<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Governance\{WpdbApplyAttemptRepository, WpdbProposalRepository};
use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Shared\Migration\MigrationStatus;

final class AdminPage
{
    public static function register(): void
    {
        add_menu_page('NHK V3', 'NHK V3', 'manage_options', 'nhk-v3', [self::class, 'render'], 'dashicons-book-alt', 26);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) wp_die('You do not have permission to view this page.');
        $status = new MigrationStatus();
        echo '<div class="wrap"><h1>NHK V3</h1><p>Trung tâm vận hành domain canonical, Graph, Governance và dữ liệu semantic.</p>';
        self::renderHealth((new HealthCheck($status))->read()); self::renderEntityLookup($status); self::renderProposalComposer(); self::renderProposalLookup($status);
        echo '<p><strong>Invariant:</strong> WordPress Post giữ editorial body; mọi semantic mutation phải qua Governance. Trang này không ghi trực tiếp vào domain tables.</p></div>';
        self::scripts();
    }

    private static function renderHealth(array $health): void
    {
        echo '<h2>Health</h2><table class="widefat striped"><tbody>';
        foreach ($health as $key => $value) echo '<tr><th scope="row">' . esc_html((string) $key) . '</th><td>' . esc_html(is_bool($value) ? ($value ? 'OK' : 'NO') : (string) $value) . '</td></tr>';
        echo '</tbody></table>';
    }

    private static function renderEntityLookup(MigrationStatus $status): void
    {
        echo '<h2>Entity lookup</h2><form method="get"><input type="hidden" name="page" value="nhk-v3"><input name="entity_type" value="' . esc_attr((string) ($_GET['entity_type'] ?? 'brand')) . '" placeholder="brand" size="16"><input name="entity_key" value="' . esc_attr((string) ($_GET['entity_key'] ?? '')) . '" placeholder="stable key hoặc UUID" size="40"><button class="button">Tra cứu</button></form>';
        if (!$status->authorityStorageReady()) { echo '<p class="notice notice-warning">Authority storage chưa sẵn sàng.</p>'; return; }
        $type = sanitize_key((string) ($_GET['entity_type'] ?? '')); $key = trim(sanitize_text_field((string) ($_GET['entity_key'] ?? ''))); if ($type === '' || $key === '') return;
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types); if (!$types->has($type)) { echo '<p class="notice notice-error">Entity type không hợp lệ.</p>'; return; }
        $repo = new WpdbAuthorityRepository(); $entity = preg_match('/^[0-9a-f-]{36}$/i', $key) === 1 ? $repo->findByCanonicalId($key) : $repo->findByStableKey($type, $key);
        if (!$entity || $entity->entityType !== $type) { echo '<p class="notice notice-info">Không tìm thấy entity.</p>'; return; }
        echo '<table class="widefat striped"><tbody><tr><th>Type</th><td>' . esc_html($entity->entityType) . '</td></tr><tr><th>Name</th><td>' . esc_html($entity->canonicalName) . '</td></tr><tr><th>Stable key</th><td><code>' . esc_html($entity->stableKey) . '</code></td></tr><tr><th>Canonical UUID</th><td><code>' . esc_html($entity->canonicalId) . '</code></td></tr><tr><th>Revision</th><td>' . esc_html((string) $entity->revision) . '</td></tr><tr><th>State</th><td>' . esc_html($entity->active() ? 'active' : 'retired') . '</td></tr><tr><th>Payload</th><td><pre>' . esc_html((string) wp_json_encode($entity->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></td></tr></tbody></table>';
    }

    private static function renderProposalLookup(MigrationStatus $status): void
    {
        echo '<h2>Governance proposal</h2><form method="get"><input type="hidden" name="page" value="nhk-v3"><input name="proposal_id" value="' . esc_attr((string) ($_GET['proposal_id'] ?? '')) . '" placeholder="Proposal UUID" size="40"><button class="button">Mở proposal</button></form>';
        $id = trim(sanitize_text_field((string) ($_GET['proposal_id'] ?? ''))); if ($id === '' || preg_match('/^[0-9a-f-]{36}$/i', $id) !== 1) return;
        if (!$status->governanceStorageReady()) { echo '<p class="notice notice-warning">Governance storage chưa sẵn sàng.</p>'; return; }
        $proposal = (new WpdbProposalRepository())->find($id); if (!$proposal) { echo '<p class="notice notice-info">Không tìm thấy proposal.</p>'; return; }
        $base = rest_url('nhk/v1/governance/proposals/' . rawurlencode($proposal->id)); $payload = wp_json_encode(['content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint], JSON_UNESCAPED_SLASHES);
        echo '<table class="widefat striped"><tbody><tr><th>State</th><td>' . esc_html($proposal->state->value) . '</td></tr><tr><th>Subject</th><td><code>' . esc_html($proposal->subjectId) . '</code></td></tr><tr><th>Operation</th><td>' . esc_html($proposal->operation) . '</td></tr><tr><th>Expected revision</th><td>' . esc_html((string) $proposal->expectedRevision) . '</td></tr><tr><th>Proposal revision</th><td>' . esc_html((string) $proposal->revision) . '</td></tr><tr><th>Dependencies</th><td><code>' . esc_html($proposal->dependencyFingerprint) . '</code></td></tr></tbody></table><p class="nhk-governance-actions">';
        self::button('Eligibility', $base . '/eligibility', 'GET'); self::button('Submit', $base . '/submit', 'POST', 'nhk_submit_proposals'); self::button('Approve', $base . '/approve', 'POST', 'nhk_approve_proposals', $payload); self::button('Reject', $base . '/reject', 'POST', 'nhk_approve_proposals'); self::button('Controlled Apply', $base . '/apply', 'POST', 'nhk_apply_proposals');
        echo '</p><pre class="nhk-governance-result" aria-live="polite"></pre>';
        $attempts = (new WpdbApplyAttemptRepository())->findByProposal($proposal->id); if ($attempts) { echo '<h3>Apply attempts</h3><table class="widefat striped"><thead><tr><th>#</th><th>State</th><th>Error code</th><th>Result</th></tr></thead><tbody>'; foreach ($attempts as $attempt) echo '<tr><td>' . esc_html((string) $attempt->number) . '</td><td>' . esc_html($attempt->state) . '</td><td>' . esc_html((string) ($attempt->errorCode ?? '')) . '</td><td>' . esc_html((string) ($attempt->resultEntityUuid ?? '')) . '</td></tr>'; echo '</tbody></table>'; }
    }

    private static function renderProposalComposer(): void
    {
        if (!current_user_can('nhk_create_proposals')) return;
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        echo '<h2>Create governed proposal</h2><p>Soạn lệnh semantic để đưa vào lifecycle Submit → Approve → Controlled Apply.</p><form id="nhk-proposal-composer" class="nhk-proposal-composer"><p><label>Operation <select name="operation"><option value="create">create</option><option value="ingest">ingest</option><option value="rename">rename</option><option value="update">update</option><option value="retire">retire</option><option value="reactivate">reactivate</option></select></label> <label>Entity type <select name="entity_type">';
        foreach ($types->all() as $definition) echo '<option value="' . esc_attr($definition->type) . '">' . esc_html($definition->type) . '</option>';
        echo '</select></label></p><p><label>Target UUID / subject ID <input name="target_uuid" size="40" placeholder="UUID cho update/retire/reactivate"></label> <label>Expected revision <input name="expected_revision" type="number" min="1" value="1" size="5"></label></p><p><label>Stable key <input name="stable_key" size="28" placeholder="brand.example"></label> <label>Name <input name="name" size="32" placeholder="Tên canonical"></label></p><p><label>Entity payload JSON<br><textarea name="entity_payload" rows="4" cols="90" placeholder="{&quot;description&quot;:&quot;...&quot;}">{}</textarea></label></p><p><button class="button button-primary" type="submit">Create proposal</button></p><pre class="nhk-composer-result" aria-live="polite"></pre></form>';
    }

    private static function button(string $label, string $url, string $method, ?string $capability = null, ?string $body = null): void
    {
        if ($capability !== null && !current_user_can($capability)) return;
        echo '<button type="button" class="button nhk-governance-action" data-url="' . esc_attr($url) . '" data-method="' . esc_attr($method) . '"' . ($body !== null ? ' data-body="' . esc_attr($body) . '"' : '') . '>' . esc_html($label) . '</button> ';
    }

    private static function scripts(): void
    {
        $nonce = wp_create_nonce('wp_rest');
        echo '<style>.nhk-governance-actions{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}.nhk-governance-result,.nhk-composer-result{max-height:240px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px}.nhk-governance-actions .button,.nhk-proposal-composer .button{cursor:pointer}.nhk-proposal-composer label{display:inline-block;margin:0 14px 10px 0}.nhk-proposal-composer input,.nhk-proposal-composer select,.nhk-proposal-composer textarea{margin-left:6px}</style><script>document.querySelectorAll(".nhk-governance-action").forEach(function(button){button.addEventListener("click",function(){var options={method:button.dataset.method,headers:{"X-WP-Nonce":"' . esc_js($nonce) . '","Content-Type":"application/json"}};if(button.dataset.body)options.body=button.dataset.body;fetch(button.dataset.url,options).then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});}).then(function(result){var output=button.closest(".wrap").querySelector(".nhk-governance-result");if(output)output.textContent=JSON.stringify(result.data,null,2);if(result.ok&&button.dataset.method!=="GET")window.setTimeout(function(){window.location.reload();},500);}).catch(function(error){var output=button.closest(".wrap").querySelector(".nhk-governance-result");if(output)output.textContent=String(error);});});});var composer=document.getElementById("nhk-proposal-composer");if(composer){composer.addEventListener("submit",function(event){event.preventDefault();var form=new FormData(composer),payload={operation:form.get("operation"),entity_type:form.get("entity_type"),target_uuid:form.get("target_uuid"),expected_revision:parseInt(form.get("expected_revision")||"1",10),idempotency_key:"admin-"+Date.now()+"-"+Math.random().toString(36).slice(2),payload:{stable_key:form.get("stable_key"),name:form.get("name"),entity_payload:{}}};try{payload.payload.entity_payload=JSON.parse(form.get("entity_payload")||"{}");}catch(error){composer.querySelector(".nhk-composer-result").textContent="Entity payload phải là JSON hợp lệ.";return;}fetch("' . esc_url_raw(rest_url('nhk/v1/governance/proposals')) . '",{method:"POST",headers:{"X-WP-Nonce":"' . esc_js($nonce) . '","Content-Type":"application/json"},body:JSON.stringify(payload)}).then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});}).then(function(result){composer.querySelector(".nhk-composer-result").textContent=JSON.stringify(result.data,null,2);}).catch(function(error){composer.querySelector(".nhk-composer-result").textContent=String(error);});});}</script>';
    }
}
