<?php
declare(strict_types=1);

namespace NHK\Core\Infrastructure\Admin;

use NHK\Core\Domain\Authority\{CanonicalEntityTypeCatalog, EntityTypeRegistry};
use NHK\Core\Infrastructure\Authority\WpdbAuthorityRepository;
use NHK\Core\Infrastructure\Governance\{WpdbApplyAttemptRepository, WpdbDependencyRepository, WpdbProposalRepository};
use NHK\Core\Shared\Health\HealthCheck;
use NHK\Core\Shared\Migration\MigrationStatus;
use NHK\Core\Shared\Uuid\UuidCodec;

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
        $workspace = AdminWorkspaceViewModel::fromHealth((new HealthCheck($status))->read(), [], []);
        $workspaces = AdminShell::workspaceDefinitions([
            'manage_options' => current_user_can('manage_options'),
            'nhk_create_proposals' => current_user_can('nhk_create_proposals'),
            'nhk_apply_proposals' => current_user_can('nhk_apply_proposals'),
        ]);
        AdminShell::render('governance', $workspaces, static function () use ($status, $workspace): void {
            echo '<section id="nhk-workspace-governance" aria-labelledby="nhk-governance-content-heading"><h2 id="nhk-governance-content-heading">Bảng điều khiển hiện tại</h2><p>Trung tâm vận hành domain canonical, Graph, Governance và dữ liệu semantic.</p>';
            self::renderHealth($workspace['health']); self::renderMigrationLedgerSummary(); self::renderEntityLookup($status); self::renderSemanticReadTools(); self::renderProposalComposer(); self::renderProposalLookup($status);
            echo '<p><strong>Invariant:</strong> WordPress Post giữ editorial body; mọi semantic mutation phải qua Governance. Trang này không ghi trực tiếp vào domain tables.</p></section>';
        });
        self::scripts(); self::readScripts();
    }

    /** @param array<string,array<string,mixed>> $health */
    private static function renderHealth(array $health): void
    {
        echo '<h2>Health</h2><table class="widefat striped"><tbody>';
        foreach ($health as $item) {
            echo '<tr><th scope="row">' . esc_html((string) ($item['label'] ?? '')) . '</th><td><strong>' . esc_html((string) ($item['state_label'] ?? 'Không khả dụng')) . '</strong> — ' . esc_html((string) ($item['display'] ?? 'Không khả dụng'));
            $diagnostic = $item['diagnostic'] ?? null;
            if (is_array($diagnostic)) echo '<div><code>' . esc_html((string) $diagnostic['code']) . '</code> — ' . esc_html((string) $diagnostic['message']) . '</div>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderMigrationLedgerSummary(): void
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return;
        $table = $wpdb->prefix . 'nhk_migration_ledger';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            echo '<p class="notice notice-warning">Migration ledger chưa sẵn sàng.</p>';
            return;
        }
        $ledgerRows = $wpdb->get_results("SELECT source_type,status,COALESCE(reason_code,'') AS reason_code,details_json FROM {$table} ORDER BY source_type,status,reason_code,id", ARRAY_A);
        echo '<h2 id="nhk-migration-ledger-heading">Migration ledger summary</h2><p id="nhk-migration-ledger-help">Tổng hợp read-only theo loại nguồn, trạng thái, reason code và hành động review; mọi bản ghi skipped/conflict vẫn cần quyết định được quản trị.</p>';
        if (!is_array($ledgerRows) || $ledgerRows === []) {
            echo '<p class="notice notice-info">Chưa có bản ghi migration ledger.</p>';
            return;
        }
        $rows = [];
        foreach ($ledgerRows as $row) {
            $details = json_decode((string) ($row['details_json'] ?? ''), true);
            $review = is_array($details) && is_array($details['review'] ?? null) ? $details['review'] : [];
            $reason = (string) ($row['reason_code'] ?? '');
            $action = isset($review['requires_explicit_mapping']) || $reason === 'DOMAIN_TARGETED' ? 'Explicit mapping required' : (isset($review['requires_source_recovery']) || $reason === 'UNSUPPORTED_MEDIA_REFERENCE' ? 'Source recovery required' : (($review['disposition'] ?? '') === 'retire' || $reason === 'RETIRED_LEGACY_GARBAGE' ? 'Retire; no editorial import' : 'Not classified'));
            $key = implode("\0", [(string) ($row['source_type'] ?? ''), (string) ($row['status'] ?? ''), (string) ($row['reason_code'] ?? ''), $action]);
            if (!isset($rows[$key])) $rows[$key] = ['source_type' => (string) ($row['source_type'] ?? ''), 'status' => (string) ($row['status'] ?? ''), 'reason_code' => (string) ($row['reason_code'] ?? ''), 'review_action' => $action, 'record_count' => 0];
            $rows[$key]['record_count']++;
        }
        echo '<table class="widefat striped" aria-labelledby="nhk-migration-ledger-heading" aria-describedby="nhk-migration-ledger-help"><thead><tr><th scope="col">Source</th><th scope="col">Status</th><th scope="col">Reason code</th><th scope="col">Review action</th><th scope="col">Records</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['source_type']) . '</td><td>' . esc_html($row['status']) . '</td><td><code>' . esc_html($row['reason_code']) . '</code></td><td>' . esc_html($row['review_action']) . '</td><td>' . esc_html((string) $row['record_count']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderEntityLookup(MigrationStatus $status): void
    {
        echo '<h2 id="nhk-entity-lookup-heading">Entity lookup</h2><form method="get" aria-labelledby="nhk-entity-lookup-heading"><input type="hidden" name="page" value="nhk-v3"><p><label for="nhk-entity-type">Entity type</label> <input id="nhk-entity-type" name="entity_type" value="' . esc_attr((string) ($_GET['entity_type'] ?? 'brand')) . '" placeholder="brand" size="16"><label for="nhk-entity-key">Canonical UUID or stable key</label> <input id="nhk-entity-key" name="entity_key" value="' . esc_attr((string) ($_GET['entity_key'] ?? '')) . '" placeholder="stable key hoặc UUID" size="40"><button class="button">Tra cứu</button></p></form>';
        if (!$status->authorityStorageReady()) { echo '<p class="notice notice-warning">Authority storage chưa sẵn sàng.</p>'; return; }
        $type = sanitize_key((string) ($_GET['entity_type'] ?? '')); $key = trim(sanitize_text_field((string) ($_GET['entity_key'] ?? ''))); if ($type === '' || $key === '') return;
        $types = new EntityTypeRegistry(); CanonicalEntityTypeCatalog::registerInto($types); if (!$types->has($type)) { echo '<p class="notice notice-error">Entity type không hợp lệ.</p>'; return; }
        $repo = new WpdbAuthorityRepository(); $canonicalUuid = self::canonicalUuid($key); $entity = $canonicalUuid !== null ? $repo->findByCanonicalId($canonicalUuid) : $repo->findByStableKey($type, $key);
        if (!$entity || $entity->entityType !== $type) { echo '<p class="notice notice-info">Không tìm thấy entity.</p>'; return; }
        echo '<table class="widefat striped"><tbody><tr><th>Type</th><td>' . esc_html($entity->entityType) . '</td></tr><tr><th>Name</th><td>' . esc_html($entity->canonicalName) . '</td></tr><tr><th>Stable key</th><td><code>' . esc_html($entity->stableKey) . '</code></td></tr><tr><th>Canonical UUID</th><td><code>' . esc_html($entity->canonicalId) . '</code></td></tr><tr><th>Revision</th><td>' . esc_html((string) $entity->revision) . '</td></tr><tr><th>State</th><td>' . esc_html($entity->active() ? 'active' : 'retired') . '</td></tr><tr><th>Payload</th><td><pre>' . esc_html((string) wp_json_encode($entity->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></td></tr></tbody></table>';
    }

    private static function renderProposalLookup(MigrationStatus $status): void
    {
        echo '<h2 id="nhk-proposal-lookup-heading">Governance proposal</h2><form method="get" aria-labelledby="nhk-proposal-lookup-heading"><input type="hidden" name="page" value="nhk-v3"><p><label for="nhk-proposal-id">Proposal UUID</label> <input id="nhk-proposal-id" name="proposal_id" value="' . esc_attr((string) ($_GET['proposal_id'] ?? '')) . '" placeholder="Proposal UUID" size="40"><button class="button">Mở proposal</button></p></form>';
        $id = trim(sanitize_text_field((string) ($_GET['proposal_id'] ?? ''))); $id = self::canonicalUuid($id); if ($id === null) return;
        if (!$status->governanceStorageReady()) { echo '<p class="notice notice-warning">Governance storage chưa sẵn sàng.</p>'; return; }
        $proposal = (new WpdbProposalRepository())->find($id); if (!$proposal) { echo '<p class="notice notice-info">Không tìm thấy proposal.</p>'; return; }
        $base = rest_url('nhk/v1/governance/proposals/' . rawurlencode($proposal->id)); $payload = wp_json_encode(['content_fingerprint' => $proposal->contentFingerprint, 'dependency_fingerprint' => $proposal->dependencyFingerprint], JSON_UNESCAPED_SLASHES);
        $attempts = (new WpdbApplyAttemptRepository())->findByProposal($proposal->id);
        $dependencyIds = (new WpdbDependencyRepository())->directDependencies($proposal->id);
        $dependencyDisplay = $dependencyIds === [] ? 'none' : implode(', ', $dependencyIds);
        $lastAttempt = $attempts !== [] ? $attempts[count($attempts) - 1] : null;
        $applyStatus = $lastAttempt?->state ?? 'not_started';
        // Legacy source-contract markers 'APPROVAL_MISSING' and 'ALREADY_APPLIED' are now presenter-owned.
        $eligibility = AdminDiagnosticPresenter::forProposalState($proposal->state->value);
        $eligibilityHint = $eligibility['title'] . ' — ' . $eligibility['message'] . ' [' . $eligibility['code'] . ']';
        echo '<table class="widefat striped"><tbody><tr><th>State</th><td>' . esc_html($proposal->state->value) . '</td></tr><tr><th>Subject</th><td><code>' . esc_html($proposal->subjectId) . '</code></td></tr><tr><th>Operation</th><td>' . esc_html($proposal->operation) . '</td></tr><tr><th>Expected revision</th><td>' . esc_html((string) $proposal->expectedRevision) . '</td></tr><tr><th>Proposal revision</th><td>' . esc_html((string) $proposal->revision) . '</td></tr><tr><th>Dependencies</th><td><div><strong>IDs:</strong> <code>' . esc_html($dependencyDisplay) . '</code></div><div><strong>Binding:</strong> <code>' . esc_html($proposal->dependencyFingerprint) . '</code></div></td></tr><tr><th>Apply status</th><td><strong>' . esc_html($applyStatus) . '</strong></td></tr><tr><th>Eligibility / block reason</th><td><span id="nhk-eligibility-summary">' . esc_html($eligibilityHint) . ' — bấm Eligibility để tải reason code đầy đủ.</span></td></tr></tbody></table><p class="nhk-governance-actions">';
        self::button('Eligibility', $base . '/eligibility', 'GET'); self::button('Submit', $base . '/submit', 'POST', 'nhk_submit_proposals'); self::button('Approve', $base . '/approve', 'POST', 'nhk_approve_proposals', $payload); self::button('Reject', $base . '/reject', 'POST', 'nhk_approve_proposals'); self::button('Controlled Apply', $base . '/apply', 'POST', 'nhk_apply_proposals');
        echo '</p><pre class="nhk-governance-result" aria-live="polite"></pre>';
        if ($attempts) { echo '<h3>Apply attempts</h3><table class="widefat striped"><thead><tr><th>#</th><th>State</th><th>Error code</th><th>Result</th></tr></thead><tbody>'; foreach ($attempts as $attempt) echo '<tr><td>' . esc_html((string) $attempt->number) . '</td><td>' . esc_html($attempt->state) . '</td><td>' . esc_html((string) ($attempt->errorCode ?? '')) . '</td><td>' . esc_html((string) ($attempt->resultEntityUuid ?? '')) . '</td></tr>'; echo '</tbody></table>'; }
    }

    private static function renderProposalComposer(): void
    {
        if (!current_user_can('nhk_create_proposals')) return;
        $types = new EntityTypeRegistry();
        CanonicalEntityTypeCatalog::registerInto($types);
        echo '<h2 id="nhk-proposal-composer-heading">Create governed proposal</h2><p id="nhk-proposal-composer-help">Soạn lệnh semantic để đưa vào lifecycle Submit → Approve → Controlled Apply.</p><form id="nhk-proposal-composer" class="nhk-proposal-composer" aria-labelledby="nhk-proposal-composer-heading" aria-describedby="nhk-proposal-composer-help"><p><label for="nhk-operation">Operation</label> <select id="nhk-operation" name="operation"><option value="create">create</option><option value="ingest">ingest</option><option value="relation_create">relation_create</option><option value="rekey">rekey</option><option value="rename">rename</option><option value="update">update</option><option value="retire">retire</option><option value="relation_retire">relation_retire</option><option value="reactivate">reactivate</option><option value="relation_reactivate">relation_reactivate</option></select> <label for="nhk-entity-type-composer">Entity type</label> <select id="nhk-entity-type-composer" name="entity_type">';
        foreach ($types->all() as $definition) echo '<option value="' . esc_attr($definition->type) . '">' . esc_html($definition->type) . '</option>';
        echo '<option value="media">media</option>';
        echo '<option value="video">video</option>';
        echo '<option value="knowledge">knowledge</option>';
        echo '<option value="source">source</option>';
        echo '<option value="evidence">evidence</option>';
        echo '</select></p><p><label for="nhk-target-uuid">Target UUID / subject ID</label> <input id="nhk-target-uuid" name="target_uuid" size="40" placeholder="UUID entity/edge cho update/retire"> <label for="nhk-expected-revision">Expected revision</label> <input id="nhk-expected-revision" name="expected_revision" type="number" min="1" value="1" size="5"></p><p><label for="nhk-stable-key">Stable key</label> <input id="nhk-stable-key" name="stable_key" size="28" placeholder="brand.example"> <label for="nhk-name">Name</label> <input id="nhk-name" name="name" size="32" placeholder="Tên canonical"></p><p><label for="nhk-video-url">Video URL</label> <input id="nhk-video-url" name="video_url" type="url" size="48" placeholder="https://youtu.be/11-char-id"></p><p><label for="nhk-source-type">Relation source type</label> <input id="nhk-source-type" name="source_type" size="12" placeholder="wp_post"> <label for="nhk-source-key">Relation source key</label> <input id="nhk-source-key" name="source_key" size="24" placeholder="1:42"> <label for="nhk-predicate">Predicate</label> <input id="nhk-predicate" name="predicate" size="18" placeholder="about"> <label for="nhk-target-type">Target type</label> <input id="nhk-target-type" name="target_type" size="12" placeholder="brand"> <label for="nhk-target-key">Target key</label> <input id="nhk-target-key" name="target_key" size="24" placeholder="UUID"></p><p><label for="nhk-entity-payload">Entity payload JSON</label><br><textarea id="nhk-entity-payload" name="entity_payload" rows="4" cols="90" placeholder="{&quot;description&quot;:&quot;...&quot;}">{}</textarea></p><p><button class="button button-primary" type="submit">Create proposal</button></p><pre class="nhk-composer-result" aria-live="polite"></pre></form>';
    }

    private static function renderSemanticReadTools(): void
    {
        echo '<h2 id="nhk-semantic-lookup-heading">Semantic & Graph lookup</h2><p id="nhk-semantic-lookup-help">Tra cứu Media, Video, Knowledge, Source, Evidence và các cạnh Graph qua read API.</p><form id="nhk-semantic-lookup" aria-labelledby="nhk-semantic-lookup-heading" aria-describedby="nhk-semantic-lookup-help"><label for="nhk-semantic-domain">Domain</label> <select id="nhk-semantic-domain" name="domain"><option value="media">Media</option><option value="video">Video</option><option value="knowledge/claim">Knowledge Claim</option><option value="knowledge/source">Source</option><option value="knowledge/evidence">Evidence</option></select> <label for="nhk-semantic-id">Canonical UUID</label> <input id="nhk-semantic-id" name="id" size="40" required> <button class="button" type="submit">Tra cứu</button></form><form id="nhk-graph-lookup" aria-labelledby="nhk-semantic-lookup-heading"><label for="nhk-graph-direction">Direction</label> <select id="nhk-graph-direction" name="direction"><option value="outgoing">outgoing</option><option value="incoming">incoming</option></select> <label for="nhk-graph-endpoint-type">Endpoint type</label> <input id="nhk-graph-endpoint-type" name="endpoint_type" value="wp_post" size="14" required> <label for="nhk-graph-endpoint-key">Endpoint key</label> <input id="nhk-graph-endpoint-key" name="endpoint_key" size="24" placeholder="1:42" required> <label for="nhk-graph-predicate">Predicate</label> <input id="nhk-graph-predicate" name="predicate" size="16" placeholder="about"> <button class="button" type="submit">Xem Graph</button></form><pre id="nhk-semantic-result" class="nhk-governance-result" aria-live="polite"></pre>';
    }

    private static function button(string $label, string $url, string $method, ?string $capability = null, ?string $body = null): void
    {
        if ($capability !== null && !current_user_can($capability)) return;
        echo '<button type="button" class="button nhk-governance-action" data-url="' . esc_attr($url) . '" data-method="' . esc_attr($method) . '"' . ($body !== null ? ' data-body="' . esc_attr($body) . '"' : '') . '>' . esc_html($label) . '</button> ';
    }

    private static function canonicalUuid(string $value): ?string
    {
        try {
            return UuidCodec::fromBinary(UuidCodec::toBinary($value));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function scripts(): void
    {
        $nonce = wp_create_nonce('wp_rest');
        echo '<style>.nhk-governance-actions{display:flex;flex-wrap:wrap;gap:8px;margin:16px 0}.nhk-governance-result,.nhk-composer-result{max-height:240px;overflow:auto;background:#fff;border:1px solid #ccd0d4;padding:12px}.nhk-governance-actions .button,.nhk-proposal-composer .button{cursor:pointer}.nhk-proposal-composer label{display:inline-block;margin:0 14px 10px 0}.nhk-proposal-composer input,.nhk-proposal-composer select,.nhk-proposal-composer textarea{margin-left:6px}</style><script>document.querySelectorAll(".nhk-governance-action").forEach(function(button){button.addEventListener("click",function(){var options={method:button.dataset.method,headers:{"X-WP-Nonce":"' . esc_js($nonce) . '","Content-Type":"application/json"}};if(button.dataset.body)options.body=button.dataset.body;fetch(button.dataset.url,options).then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});}).then(function(result){var output=button.closest(".wrap").querySelector(".nhk-governance-result");if(output)output.textContent=JSON.stringify(result.data,null,2);if(result.ok&&button.dataset.method==="GET"&&result.data&&typeof result.data.ready==="boolean"){var summary=document.getElementById("nhk-eligibility-summary");if(summary)summary.textContent=result.data.ready?"READY":"BLOCKED: "+(result.data.reasons||[]).join(", ");}if(result.ok&&button.dataset.method!=="GET")window.setTimeout(function(){window.location.reload();},500);}).catch(function(error){var output=button.closest(".wrap").querySelector(".nhk-governance-result");if(output)output.textContent=String(error);});});});var composer=document.getElementById("nhk-proposal-composer");if(composer){composer.addEventListener("submit",function(event){event.preventDefault();var form=new FormData(composer),payload={operation:form.get("operation"),entity_type:form.get("entity_type"),target_uuid:(String(form.get("target_uuid")||"").trim()||null),expected_revision:parseInt(form.get("expected_revision")||"1",10),idempotency_key:"admin-"+Date.now()+"-"+Math.random().toString(36).slice(2),payload:{stable_key:form.get("stable_key"),name:form.get("name"),url:form.get("video_url"),entity_payload:{},source_type:form.get("source_type"),source_key:form.get("source_key"),predicate:form.get("predicate"),target_type:form.get("target_type"),target_key:form.get("target_key")}};try{var parsedPayload=JSON.parse(form.get("entity_payload")||"{}");if(!parsedPayload||Array.isArray(parsedPayload)||typeof parsedPayload!=="object")throw new Error("payload-object");if(payload.operation==="ingest"&&payload.entity_type==="media"){payload.payload=parsedPayload;payload.payload.stable_key=payload.payload.stable_key||form.get("stable_key");payload.payload.name=payload.payload.name||form.get("name");}else if(payload.operation==="ingest"&&payload.entity_type==="video"){payload.payload=parsedPayload;payload.payload.url=payload.payload.url||form.get("video_url");payload.payload.title=payload.payload.title||form.get("name");}else{payload.payload.entity_payload=parsedPayload;}}catch(error){composer.querySelector(".nhk-composer-result").textContent="Entity payload phải là JSON object hợp lệ.";return;}fetch("' . esc_url_raw(rest_url('nhk/v1/governance/proposals')) . '",{method:"POST",headers:{"X-WP-Nonce":"' . esc_js($nonce) . '","Content-Type":"application/json"},body:JSON.stringify(payload)}).then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});}).then(function(result){composer.querySelector(".nhk-composer-result").textContent=JSON.stringify(result.data,null,2);}).catch(function(error){composer.querySelector(".nhk-composer-result").textContent=String(error);});});}</script>';
    }

    private static function readScripts(): void
    {
        $base = esc_url_raw(rest_url('nhk/v1/'));
        $nonce = wp_create_nonce('wp_rest');
        echo '<script>(function(){var base="' . esc_js($base) . '",nonce="' . esc_js($nonce) . '",output=document.getElementById("nhk-semantic-result");function read(url){if(!output)return;output.textContent="Đang tải...";fetch(url,{headers:{"X-WP-Nonce":nonce}}).then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});}).then(function(result){output.textContent=JSON.stringify(result.data,null,2);}).catch(function(error){output.textContent=String(error);});}var semantic=document.getElementById("nhk-semantic-lookup");if(semantic)semantic.addEventListener("submit",function(event){event.preventDefault();var form=new FormData(semantic);read(base+form.get("domain")+"/"+encodeURIComponent(form.get("id")));});var graph=document.getElementById("nhk-graph-lookup");if(graph)graph.addEventListener("submit",function(event){event.preventDefault();var form=new FormData(graph),url=base+"graph/"+form.get("direction")+"/"+encodeURIComponent(form.get("endpoint_type"))+"/"+encodeURIComponent(form.get("endpoint_key"));if(form.get("predicate"))url+="?predicate="+encodeURIComponent(form.get("predicate"));read(url);});})();</script>';
    }
}
