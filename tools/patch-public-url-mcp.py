from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

def patch(rel, old, new):
    path = ROOT / rel
    text = path.read_text()
    if old not in text:
        raise SystemExit(f"marker not found: {rel}: {old[:80]!r}")
    path.write_text(text.replace(old, new, 1))

# Tool catalog: one bounded audit and one guarded reprojection action.
p = "public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php"
marker = "            self::tool('nhk.proposal.create',"
addition = """            self::tool('nhk.public-url.audit', 'Audit all canonical public URL owners and return deterministic KEEP, ALLOCATE, CHANGE or BLOCKED decisions without writing data.', [], []),\n            self::tool('nhk.public-url.reproject', 'Apply the pre-public canonical URL reprojection only after a clean audit, explicit confirmation and idempotency binding, then run read-back.', ['idempotency_key' => ['type' => 'string', 'minLength' => 1], 'pre_public_confirmed' => ['type' => 'boolean']], ['idempotency_key', 'pre_public_confirmed'], true),\n"""
patch(p, marker, addition + marker)

# Ability bridge and dedicated capability.
p = "public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php"
patch(p, "        'nhk.proposal.review' => 'nhk-v3/proposal-review',\n", "        'nhk.proposal.review' => 'nhk-v3/proposal-review',\n        'nhk.public-url.audit' => 'nhk-v3/public-url-audit',\n")
patch(p, "        'nhk.article.ingest' => 'nhk-v3/article-ingest',\n", "        'nhk.public-url.reproject' => 'nhk-v3/public-url-reproject',\n        'nhk.article.ingest' => 'nhk-v3/article-ingest',\n")
patch(p, "            'nhk.proposal.apply' => 'nhk_apply_proposals',\n", "            'nhk.proposal.apply' => 'nhk_apply_proposals',\n            'nhk.public-url.audit', 'nhk.public-url.reproject' => 'nhk_manage_public_urls',\n")
patch(p, "            'nhk.search' => 'NHK Search',\n", "            'nhk.public-url.audit' => 'NHK Public URL Audit',\n            'nhk.public-url.reproject' => 'NHK Public URL Reproject',\n            'nhk.search' => 'NHK Search',\n")

p = "public/wp-content/plugins/nhk-core/src/Application/Governance/GovernanceCapabilities.php"
patch(p, "'nhk_curate_dictionary'];", "'nhk_curate_dictionary','nhk_manage_public_urls'];")

# MCP transport: inject the maintenance service, capability gate and calls.
p = "public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php"
patch(p, "use NHK\\Core\\Application\\Knowledge\\CanonicalDependencyValidator;\n", "use NHK\\Core\\Application\\Knowledge\\CanonicalDependencyValidator;\nuse NHK\\Core\\Application\\PublicIdentity\\PublicUrlMaintenanceService;\n")
patch(p, "        private ?CanonicalDependencyValidator $dependencies = null,\n    ) {}", "        private ?CanonicalDependencyValidator $dependencies = null,\n        private ?PublicUrlMaintenanceService $publicUrls = null,\n    ) {}")
patch(p, "            'nhk.proposal.apply' => 'nhk_apply_proposals',\n", "            'nhk.proposal.apply' => 'nhk_apply_proposals',\n            'nhk.public-url.audit', 'nhk.public-url.reproject' => 'nhk_manage_public_urls',\n")
patch(p, "            'nhk.search' => $this->read->search", "            'nhk.public-url.audit' => $this->publicUrls?->audit() ?? throw new \\RuntimeException('PUBLIC_URL_MAINTENANCE_UNAVAILABLE'),\n            'nhk.public-url.reproject' => $this->publicUrls?->reproject((string) ($arguments['idempotency_key'] ?? ''), (bool) ($arguments['pre_public_confirmed'] ?? false)) ?? throw new \\RuntimeException('PUBLIC_URL_MAINTENANCE_UNAVAILABLE'),\n            'nhk.search' => $this->read->search")

# Machine-readable capability manifest.
p = "public/wp-content/plugins/nhk-core/src/Application/Mcp/McpCapabilityManifest.php"
patch(p, "            'product' => ['owner' => 'authority',", "            'public_url' => ['owner' => 'public_identity', 'endpoint_types' => [], 'tools' => ['nhk.public-url.audit', 'nhk.public-url.reproject'], 'seo_preflight' => true, 'relation_support' => false, 'media_support' => false, 'read_back' => true],\n            'product' => ['owner' => 'authority',")
patch(p, "['article', 'category', 'media', 'video', 'knowledge', 'source', 'evidence']", "['article', 'category', 'media', 'video', 'knowledge', 'source', 'evidence', 'public_url']")

# Existing exact catalog test must reflect the new public contract.
p = "public/wp-content/plugins/nhk-core/tests/Unit/McpContractTest.php"
patch(p, "            'nhk.proposal.create',\n", "            'nhk.public-url.audit',\n            'nhk.public-url.reproject',\n            'nhk.proposal.create',\n")

# Compose live service into the one MCP transport.
p = "public/wp-content/plugins/nhk-core/src/Plugin.php"
needle = "            (new McpApi(new McpTransport($mcpRead, $mcpGovernance, static fn (string $capability): bool => current_user_can($capability), static fn (string $value): bool => in_array($value, $allowedOrigins, true), $articleHandler, $videoIntake, $wordpressAttachments, $categoryGateway, $draftGateway, new CanonicalDependencyValidator($claims, $sources, $evidence))))->register();"
replacement = """            $publicUrlMaintenance = (new \\NHK\\Core\\Infrastructure\\PublicIdentity\\WordPressPublicUrlMaintenanceRuntime($wpdb, $authority, $types, $publicContexts, $videos, $media, $assets, new \\NHK\\Core\\Infrastructure\\PublicIdentity\\WpdbPublicIdentityRepository($wpdb)))->service();\n            (new McpApi(new McpTransport($mcpRead, $mcpGovernance, static fn (string $capability): bool => current_user_can($capability), static fn (string $value): bool => in_array($value, $allowedOrigins, true), $articleHandler, $videoIntake, $wordpressAttachments, $categoryGateway, $draftGateway, new CanonicalDependencyValidator($claims, $sources, $evidence), $publicUrlMaintenance)))->register();"""
patch(p, needle, replacement)

# Video unit fixtures: the new contract requires an eligible persisted-slug
# snapshot in isolated tests; production central Public Identity still wins.
p = "public/wp-content/plugins/nhk-core/tests/Unit/MediaVideoPageQueryTest.php"
eligible = "['public_identity' => ['current_slug' => 'reference'], 'source_snapshot' => ['availability' => 'available', 'embeddable' => true], 'editorial' => ['title' => 'Reference', 'summary' => 'Summary'], 'hub' => ['primary' => '06'], 'provenance' => ['kind' => 'TEST'], 'semantic_attachments' => [['target_id' => '22222222-2222-4222-8222-222222222222']]]"
patch(p, "$video = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ', 'Reference');", "$video = Video::fromUrl('https://youtu.be/dQw4w9WgXcQ', 'Reference', " + eligible + ");")
patch(p, "$video = Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Reference', ['source' => 'internal-test']);", "$video = Video::fromUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'Reference', " + eligible + ");")

p = "public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticCoreTest.php"
patch(p, "'public_identity' => ['current_slug' => 'nhk-title']", "'public_identity' => ['current_slug' => 'nha-kho-title']")
patch(p, "https://nhk.example/video/nhk-title-dqw4w9wgxcq/", "https://nhk.example/video/nha-kho-title/")

p = "public/wp-content/plugins/nhk-core/tests/Unit/VideoSemanticDossierTest.php"
patch(p, "/video/am-thanh-hien-vat-dqw4w9wgxcq/", "/video/am-thanh-hien-vat/")

print('public URL MCP patch applied')
