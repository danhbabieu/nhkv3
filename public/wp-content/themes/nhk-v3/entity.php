<?php
/* public seo_projection remains the canonical URL/indexability owner. */
$context = $GLOBALS['nhk_core_entity_context'] ?? null;
$labels = ['brand' => 'Thương hiệu', 'model' => 'Mẫu đồng hồ', 'variant' => 'Biến thể', 'movement' => 'Bộ máy', 'music' => 'Bản nhạc', 'component' => 'Linh kiện', 'classification' => 'Phân loại', 'specimen' => 'Hiện vật', 'product' => 'Sản phẩm'];
$archivePaths = ['brand' => '/thuong-hieu/', 'model' => '/mau/', 'variant' => '/bien-the/', 'movement' => '/bo-may/', 'music' => '/ban-nhac/', 'component' => '/linh-kien/', 'classification' => '/phan-loai/', 'specimen' => '/hien-vat/', 'product' => '/san-pham/'];
$facetLabels = ['identity' => 'Định danh', 'chronology' => 'Niên đại', 'recognition' => 'Dấu nhận diện', 'configuration' => 'Cấu hình đã ghi nhận', 'movement' => 'Kết cấu & bộ máy', 'music' => 'Chuông & nhạc', 'component' => 'Linh kiện', 'provenance' => 'Nguồn gốc & tư liệu', 'domestic_cultural' => 'Bối cảnh sử dụng', 'rarity_frequency' => 'Mức độ gặp', 'specimen_observation' => 'Quan sát hiện vật'];
$relationLabels = ['brands' => 'Thương hiệu', 'models' => 'Mẫu đồng hồ', 'variants' => 'Biến thể', 'movements' => 'Bộ máy', 'music' => 'Bản nhạc', 'components' => 'Linh kiện', 'classifications' => 'Phân loại', 'specimens' => 'Hiện vật', 'products' => 'Sản phẩm', 'articles' => 'Bài viết', 'media' => 'Hình ảnh', 'videos' => 'Video'];
$type = is_array($context) ? (string) ($context['type'] ?? '') : '';
$profileKey = is_array($context) ? trim((string) ($context['profile'] ?? '')) : '';
$label = $profileKey === 'clock_type' ? 'Nhóm đồng hồ' : ($labels[$type] ?? 'Khám phá');
$fallback = get_theme_file_uri('/assets/default-archive.svg');
$nhkReaderType = static function (array $item): string {
    if (trim((string) ($item['profile_key'] ?? '')) === '') return nhk_v3_public_type((string) ($item['type'] ?? ''));
    return nhk_v3_public_type((string) ($item['type'] ?? ''), (string) $item['profile_key']);
};
get_header();
?>
<main id="main-content" class="site-main entity-shell entity-v2">
<?php if (is_array($context) && ($context['mode'] ?? '') === 'detail' && is_array($context['entity'] ?? null)): $entity = $context['entity'];
    $profileKey = trim((string) ($entity['profile_key'] ?? $profileKey));
    $label = $profileKey === 'clock_type' ? 'Nhóm đồng hồ' : ($labels[$type] ?? 'Khám phá');
    $dossier = is_array($entity['dossier'] ?? null) && ($entity['dossier']['status'] ?? '') === 'AVAILABLE' ? $entity['dossier'] : null;
    $profile = is_array($entity['dossier']['profile'] ?? null) ? $entity['dossier']['profile'] : [];
    $view = is_array($entity['dossier']['presentation'] ?? null) ? $entity['dossier']['presentation'] : $profile;
    $identity = is_array($profile['identity'] ?? null) ? $profile['identity'] : (is_array($dossier['identity'] ?? null) ? $dossier['identity'] : ['type' => $type, 'name' => (string) ($entity['name'] ?? ''), 'payload' => is_array($entity['payload'] ?? null) ? $entity['payload'] : [], 'url' => $entity['url'] ?? null]);
    $payload = is_array($identity['payload'] ?? null) ? $identity['payload'] : [];
    $visiblePayload = [];
    foreach ($payload as $key => $value) {
        if (in_array((string) $key, ['description', 'summary'], true) || str_ends_with((string) $key, '_uuid')) continue;
        if ($profileKey === 'clock_type' && in_array(strtolower((string) $key), ['family', 'profile'], true)) continue;
        if ($value === null || $value === '' || (is_array($value) && $value === [])) continue;
        $visiblePayload[$key] = $value;
    }
    $knowledge = is_array($profile['knowledge'] ?? null) ? $profile['knowledge'] : (is_array($dossier['knowledge'] ?? null) ? $dossier['knowledge'] : (is_array($entity['knowledge'] ?? null) ? $entity['knowledge'] : []));
    $claimProjection = is_array($entity['claim_projection'] ?? null) ? $entity['claim_projection'] : [];
    $publishedClaimProjection = is_array($entity['published_claim_projection'] ?? null) ? $entity['published_claim_projection'] : [];
    $facets = is_array($knowledge['facets'] ?? null) ? $knowledge['facets'] : [];
    if (($claimProjection['status'] ?? '') === 'available') $facets = [];
    $knowledgeCount = is_numeric($claimProjection['claim_count'] ?? null) ? (int) $claimProjection['claim_count'] : (int) ($knowledge['claim_count'] ?? 0);
    $legacyMedia = is_array($entity['media'] ?? null) ? $entity['media'] : [];
    $legacyGallery = is_array($legacyMedia['gallery'] ?? null) ? $legacyMedia['gallery'] : [];
    $primary = is_array($profile['primary_media'] ?? null) ? $profile['primary_media'] : (is_array($dossier['primary_media'] ?? null) ? $dossier['primary_media'] : (is_array($legacyMedia['representative'] ?? null) ? $legacyMedia['representative'] : null));
    $gallery = is_array($profile['media_gallery'] ?? null) ? $profile['media_gallery'] : (is_array($dossier['media_gallery'] ?? null) ? $dossier['media_gallery'] : $legacyGallery);
    $relationSections = is_array($profile['relation_sections'] ?? null) ? $profile['relation_sections'] : (is_array($dossier['relation_sections'] ?? null) ? $dossier['relation_sections'] : []);
    $relatedGroups = is_array($entity['related'] ?? null) ? $entity['related'] : [];
    if ($type === 'brand') unset($relatedGroups['entities']);
    $heroImage = trim((string) ($primary['url'] ?? '')) ?: $fallback;
    $lexicalText = (string) ($identity['name'] ?? '') . ' ' . wp_json_encode($payload);
    foreach ($facets as $claims) foreach ((array) $claims as $claim) if (is_array($claim)) $lexicalText .= ' ' . (string) ($claim['text'] ?? '');
    $dictionaryTerms = apply_filters('nhk_v3_public_dictionary_terms_for_text', [], $lexicalText);
    $dictionaryTerms = is_array($dictionaryTerms) ? $dictionaryTerms : [];
    $warnings = is_array($profile['warnings'] ?? null) ? $profile['warnings'] : (is_array($dossier['warnings'] ?? null) ? $dossier['warnings'] : []);
    $coverage = is_array($profile['coverage'] ?? null) ? $profile['coverage'] : (is_array($dossier['coverage'] ?? null) ? $dossier['coverage'] : []);
    $hasLegacyAggregation = $dossier === null && $type === 'brand' && is_array($entity['aggregation'] ?? null) && array_filter($entity['aggregation']);
    $hasClockTypeAggregation = $type === 'brand' && is_array($entity['aggregation']['clock_types'] ?? null) && $entity['aggregation']['clock_types'] !== [];
    $collectorProfile = is_array($dossier['collector_profile'] ?? null) ? $dossier['collector_profile'] : (is_array($entity['collector_profile'] ?? null) ? $entity['collector_profile'] : []);
    $collectorAvailable = $type === 'classification' && ($collectorProfile['status'] ?? '') === 'available';
    $collectorFacets = is_array($collectorProfile['facets'] ?? null) ? $collectorProfile['facets'] : [];
    $collectorLabels = [
        'display_form' => 'Dáng thức hiển thị', 'dimensions' => 'Kích thước', 'dating' => 'Niên đại',
        'case_styles' => 'Kiểu vỏ', 'motifs' => 'Mô-típ', 'materials' => 'Vật liệu',
        'craft_modes' => 'Phương thức chế tác', 'production_scale' => 'Quy mô sản xuất',
        'movement_family' => 'Họ bộ máy', 'running_duration' => 'Thời gian chạy',
        'drive_system' => 'Hệ dẫn động', 'functions' => 'Chức năng', 'sound' => 'Âm thanh',
        'music' => 'Âm nhạc', 'automata' => 'Cơ cấu tự động', 'night_shutoff' => 'Tắt chuông ban đêm',
        'condition_guidance' => 'Tình trạng hiện vật', 'originality_guidance' => 'Độ nguyên bản',
        'provenance' => 'Nguồn gốc', 'rarity' => 'Độ hiếm', 'origin_certification' => 'Xác nhận xuất xứ',
    ];
    $collectorOrder = ['display_form', 'case_styles', 'dimensions', 'dating', 'movement_family', 'running_duration', 'drive_system', 'functions', 'sound', 'music', 'automata', 'night_shutoff', 'materials', 'craft_modes', 'production_scale', 'condition_guidance', 'originality_guidance', 'provenance', 'rarity', 'origin_certification'];
    $readerGuide = is_array($view['reader_guide'] ?? null) ? $view['reader_guide'] : [];
    $readerGuideLabels = ['definition' => 'Đây là gì?', 'context' => 'Vai trò & bối cảnh', 'collector_value' => 'Giá trị sưu tầm', 'collector_focus' => 'Người sưu tầm thường xem gì?'];
    $uniquePublicItems = static function (array $items): array {
        $keys = array_unique(array_values(array_filter(array_map(static function ($item): string {
            if (!is_array($item)) return '';
            return trim((string) ($item['canonical_id'] ?? $item['url'] ?? $item['title'] ?? $item['name'] ?? ''));
        }, $items))));
        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $key = trim((string) ($item['canonical_id'] ?? $item['url'] ?? $item['title'] ?? $item['name'] ?? ''));
            if ($key === '' || !in_array($key, $keys, true)) continue;
            $result[$key] ??= $item;
        }
        return array_values($result);
    };
?>
  <?php $breadcrumbItems = is_array($view['breadcrumbs'] ?? null) ? $view['breadcrumbs'] : []; if ($breadcrumbItems === []) $breadcrumbItems = [['name' => (string) ($identity['name'] ?? '')]]; get_template_part('template-parts/presentation/breadcrumbs', null, ['items' => array_merge([['label' => $label, 'url' => (string) ($context['archive_url'] ?? home_url($archivePaths[$type] ?? '/'))]], $breadcrumbItems)]); ?>
  <?php get_template_part('template-parts/presentation/entity-hero', null, ['view' => $view, 'label' => $label, 'fallback' => $fallback, 'name' => (string) ($identity['name'] ?? '')]); ?>
  <?php $sectionStatus = is_array($view['section_status'] ?? null) ? $view['section_status'] : []; $localSections = ['dinh-huong' => ['label' => 'Định hướng đọc', 'available' => true], 'ho-so' => ['label' => 'Tổng quan', 'available' => $visiblePayload !== [] || $sectionStatus === []], 'tri-thuc' => ['label' => 'Tri thức', 'available' => $facets !== [] || $claimProjection !== [] || (($sectionStatus['knowledge']['status'] ?? '') === 'AVAILABLE')], 'hinh-anh' => ['label' => 'Hình ảnh', 'available' => $gallery !== [] || (($sectionStatus['media']['status'] ?? '') === 'AVAILABLE')], 'video' => ['label' => 'Video', 'available' => !empty($relationSections['videos']) || (($sectionStatus['videos']['status'] ?? '') === 'AVAILABLE')], 'bai-viet' => ['label' => 'Bài viết', 'available' => !empty($relationSections['articles']) || (($sectionStatus['articles']['status'] ?? '') === 'AVAILABLE')]]; get_template_part('template-parts/presentation/local-section-nav', null, ['sections' => $localSections]); ?>

  <div class="semantic-layout">
    <div class="semantic-main">
      <section id="dinh-huong" class="dossier-section reader-guide" aria-labelledby="reader-guide-title">
        <div class="section-head"><div><p class="eyebrow">Mở hồ sơ</p><h2 id="reader-guide-title">Đọc hồ sơ này theo bốn câu hỏi</h2></div></div>
        <div class="reader-guide-grid">
          <?php foreach ($readerGuideLabels as $guideKey => $guideLabel): $guide = is_array($readerGuide[$guideKey] ?? null) ? $readerGuide[$guideKey] : ['status' => 'EMPTY', 'items' => []]; $guideItems = is_array($guide['items'] ?? null) ? array_values(array_filter($guide['items'], static fn($item): bool => is_array($item) && trim((string) ($item['text'] ?? '')) !== '')) : []; ?>
          <article class="reader-guide-card<?php echo $guideItems === [] ? ' is-empty' : ''; ?>">
            <p class="eyebrow"><?php echo esc_html($guideLabel); ?></p>
            <?php if ($guideItems !== []): ?><p class="reader-guide-lead"><?php echo esc_html(nhk_v3_public_copy((string) $guideItems[0]['text'])); ?></p><?php if (count($guideItems) > 1): ?><ul><?php foreach (array_slice($guideItems, 1, 3) as $guideItem): ?><li><?php echo esc_html(nhk_v3_public_copy((string) $guideItem['text'])); ?></li><?php endforeach; ?></ul><?php endif; ?><?php else: ?><p class="reader-guide-empty">Nội dung đang được bổ sung từ các ghi nhận và nguồn phù hợp.</p><?php endif; ?>
          </article>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if ($visiblePayload !== []): ?>
      <section id="ho-so" class="dossier-section"><div class="section-head"><div><p class="eyebrow">Hồ sơ</p><h2>Thông tin định danh</h2></div></div><dl class="entity-facts"><?php foreach ($visiblePayload as $key => $value): ?><dt><?php echo esc_html(nhk_v3_public_label((string) $key)); ?></dt><dd><?php echo esc_html(nhk_v3_public_value($value)); ?></dd><?php endforeach; ?></dl></section>
      <?php endif; ?>

      <?php if ($profileKey === 'clock_type' && ($profile['hierarchy']['status'] ?? '') === 'AVAILABLE'): $hierarchy = is_array($profile['hierarchy'] ?? null) ? $profile['hierarchy'] : []; $parent = is_array($hierarchy['parent'] ?? null) && $hierarchy['parent'] !== [] ? $hierarchy['parent'] : null; $children = is_array($hierarchy['children'] ?? null) ? $hierarchy['children'] : []; ?>
      <section id="phan-cap" class="dossier-section clock-type-hierarchy"><div class="section-head"><div><p class="eyebrow">Phân cấp</p><h2>Vị trí trong nhóm đồng hồ</h2></div></div><?php if ($parent !== null): ?><p>Thuộc nhóm: <strong><?php $parentUrl = nhk_v3_public_url($parent['url'] ?? null); if ($parentUrl !== ''): ?><a href="<?php echo esc_url($parentUrl); ?>"><?php echo esc_html((string) ($parent['name'] ?? '')); ?></a><?php else: echo esc_html((string) ($parent['name'] ?? '')); endif; ?></strong></p><?php endif; ?><?php if ($children !== []): ?><h3>Nhóm con</h3><ul><?php foreach ($children as $child): if (!is_array($child) || trim((string) ($child['name'] ?? '')) === '') continue; $childUrl = nhk_v3_public_url($child['url'] ?? null); ?><li><?php if ($childUrl !== ''): ?><a href="<?php echo esc_url($childUrl); ?>"><?php endif; ?><?php echo esc_html((string) $child['name']); ?><?php if ($childUrl !== ''): ?></a><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?><?php if ($parent === null && $children === []): ?><p>Chưa có quan hệ phân cấp được ghi nhận.</p><?php endif; ?></section>
      <?php endif; ?>

      <?php if ($warnings !== []): ?>
      <section class="dossier-section dossier-notices"><div class="section-head"><div><p class="eyebrow">Phạm vi tư liệu</p><h2>Lưu ý khi đọc hồ sơ</h2></div></div><ul class="dossier-warning-list">
        <?php foreach ($warnings as $warning): $message = match ((string) $warning) {
            'PUBLIC_CLAIMS_WITHOUT_EVIDENCE' => 'Một số ghi nhận công khai hiện chưa có trích dẫn nguồn hiển thị.',
            'PUBLIC_CONTRADICTION_PRESENT' => 'Tư liệu đang có ghi nhận mâu thuẫn; nên đọc cùng phần nguồn để đối chiếu.',
            'SPECIMEN_OBSERVATION_SCOPE_ONLY' => 'Các ghi nhận hiện có chỉ ở phạm vi quan sát hiện vật; không nên suy rộng thành đặc điểm chung.',
            'GRAPH_UNAVAILABLE' => 'Phần quan hệ liên quan hiện chưa truy vấn được đầy đủ.',
            'GRAPH_QUERY_UNSUPPORTED' => 'Phần quan hệ hiện chưa có đủ điều kiện để tổng hợp.',
            default => '',
        }; if ($message === '') continue; ?><li><?php echo esc_html($message); ?></li><?php endforeach; ?>
      </ul></section>
      <?php endif; ?>

      <?php if ($collectorAvailable): $collectorCoverage = is_array($collectorProfile['coverage'] ?? null) ? $collectorProfile['coverage'] : []; $collectorOverview = is_array($collectorProfile['overview'] ?? null) ? $collectorProfile['overview'] : []; $collectorUnresolved = is_array($collectorProfile['unresolved'] ?? null) ? $collectorProfile['unresolved'] : []; $collectorArticles = $uniquePublicItems(is_array($collectorProfile['related_articles'] ?? null) ? $collectorProfile['related_articles'] : []); $collectorMedia = $uniquePublicItems(is_array($collectorProfile['media'] ?? null) ? $collectorProfile['media'] : []); $collectorVideos = $uniquePublicItems(is_array($collectorProfile['videos'] ?? null) ? $collectorProfile['videos'] : []); $collectorMakers = $uniquePublicItems(is_array($collectorProfile['makers'] ?? null) ? $collectorProfile['makers'] : []); $collectorHasClaims = false; foreach ($collectorFacets as $collectorFacetItems) foreach ((array) $collectorFacetItems as $collectorClaim) if (is_array($collectorClaim) && trim((string) ($collectorClaim['text'] ?? '')) !== '') $collectorHasClaims = true; $collectorHasContent = $collectorHasClaims || $collectorUnresolved !== [] || $collectorArticles !== [] || $collectorMedia !== [] || $collectorVideos !== [] || $collectorMakers !== []; ?>
      <?php if ($collectorHasContent): ?>
      <section id="collector-profile" class="dossier-section collector-profile" aria-labelledby="collector-profile-title">
        <div class="section-head"><div><p class="eyebrow">Góc nhìn người sưu tầm</p><h2 id="collector-profile-title">Hồ sơ tra cứu nhanh</h2></div></div>
        <?php if (($collectorOverview['name'] ?? '') !== ''): ?><p class="entity-lead">Hồ sơ này tập trung vào <?php echo esc_html(nhk_v3_public_brand_text((string) $collectorOverview['name'])); ?> và các ghi nhận thuộc đúng nhánh phân loại.</p><?php endif; ?>
        <?php $collectorStats = []; foreach (['knowledge_count' => 'ghi nhận', 'media_count' => 'hình ảnh', 'video_count' => 'video'] as $collectorStatKey => $collectorStatLabel) { $collectorStatValue = (int) ($collectorCoverage[$collectorStatKey] ?? 0); if ($collectorStatValue > 0) $collectorStats[] = [$collectorStatValue, $collectorStatLabel]; } if (!empty($collectorCoverage['truncated'])) $collectorStats[] = ['', 'Đang hiển thị một phần tư liệu']; if ($collectorStats !== []): ?><div class="dossier-stats collector-summary"><?php foreach ($collectorStats as $collectorStat): ?><span><?php if ($collectorStat[0] !== ''): ?><strong><?php echo esc_html((string) $collectorStat[0]); ?></strong><?php endif; ?><?php echo esc_html($collectorStat[1]); ?></span><?php endforeach; ?></div><?php endif; ?>
        <?php foreach ($collectorOrder as $collectorGroup): $collectorClaims = is_array($collectorFacets[$collectorGroup] ?? null) ? $collectorFacets[$collectorGroup] : []; if ($collectorClaims === []) continue; $collectorHeading = $collectorLabels[$collectorGroup] ?? 'Ghi nhận'; ?>
        <section class="collector-facet" data-collector-facet="<?php echo esc_attr($collectorGroup); ?>"><div class="section-head"><div><p class="eyebrow">Tri thức có cấu trúc</p><h3><?php echo esc_html($collectorHeading); ?></h3></div></div><div class="knowledge-stack">
          <?php foreach ($collectorClaims as $collectorClaim): if (!is_array($collectorClaim) || trim((string) ($collectorClaim['text'] ?? '')) === '') continue; $collectorStatus = (string) ($collectorClaim['status'] ?? 'unresolved'); $collectorStatusLabel = $collectorStatus === 'verified' ? 'Đã có nguồn' : ($collectorStatus === 'partial' ? 'Cần bổ sung nguồn' : 'Chưa phân loại'); ?><article class="knowledge-claim"><p><?php echo esc_html(nhk_v3_public_copy((string) $collectorClaim['text'])); ?></p><small class="scope-note"><?php echo esc_html($collectorStatusLabel); ?><?php if (isset($collectorClaim['evidence_count'])): ?> · <?php echo esc_html((string) $collectorClaim['evidence_count']); ?> chứng cứ<?php endif; ?></small></article><?php endforeach; ?>
        </div></section>
        <?php endforeach; ?>
        <?php if ($collectorUnresolved !== []): ?><section class="collector-facet collector-unresolved"><div class="section-head"><div><p class="eyebrow">Cần đối chiếu</p><h3>Một số ghi nhận chưa đủ điều kiện xếp nhóm</h3></div></div><div class="knowledge-stack"><?php foreach ($collectorUnresolved as $collectorClaim): if (!is_array($collectorClaim) || trim((string) ($collectorClaim['text'] ?? '')) === '') continue; ?><article class="knowledge-claim"><p><?php echo esc_html(nhk_v3_public_copy((string) $collectorClaim['text'])); ?></p><small class="scope-note">Chưa xếp vào nhóm hiển thị</small></article><?php endforeach; ?></div></section><?php endif; ?>
        <?php if ($collectorArticles !== []): ?><section class="collector-related collector-articles"><div class="section-head"><div><p class="eyebrow">Biên tập</p><h3>Bài viết liên quan</h3></div></div><div class="related-grid"><?php foreach ($collectorArticles as $collectorItem): if (!is_array($collectorItem)) continue; $collectorTitle = trim((string) ($collectorItem['title'] ?? $collectorItem['name'] ?? '')); if ($collectorTitle === '') continue; $collectorUrl = nhk_v3_public_url($collectorItem['url'] ?? null); ?><article class="related-card"><?php if ($collectorUrl !== ''): ?><a href="<?php echo esc_url($collectorUrl); ?>"><strong><?php echo esc_html(nhk_v3_public_brand_text($collectorTitle)); ?></strong></a><?php else: ?><strong><?php echo esc_html(nhk_v3_public_brand_text($collectorTitle)); ?></strong><?php endif; ?></article><?php endforeach; ?></div></section><?php endif; ?>
        <?php if ($collectorMedia !== [] || $collectorVideos !== []): ?><section class="collector-related collector-media-video"><div class="section-head"><div><p class="eyebrow">Tư liệu trực quan</p><h3>Hình ảnh và video</h3></div></div><div class="related-grid"><?php foreach (array_merge($collectorMedia, $collectorVideos) as $collectorItem): if (!is_array($collectorItem)) continue; $collectorTitle = trim((string) ($collectorItem['title'] ?? $collectorItem['name'] ?? '')); if ($collectorTitle === '') continue; $collectorUrl = nhk_v3_public_url($collectorItem['url'] ?? null); $collectorKind = ($collectorItem['type'] ?? '') === 'video' ? 'Video' : 'Hình ảnh'; ?><article class="related-card"><span class="related-type"><?php echo esc_html($collectorKind); ?></span><?php if ($collectorUrl !== ''): ?><a href="<?php echo esc_url($collectorUrl); ?>"><strong><?php echo esc_html(nhk_v3_public_brand_text($collectorTitle)); ?></strong></a><?php else: ?><strong><?php echo esc_html(nhk_v3_public_brand_text($collectorTitle)); ?></strong><?php endif; ?></article><?php endforeach; ?></div></section><?php endif; ?>
        <?php if ($collectorMakers !== []): ?><section class="collector-related collector-makers"><div class="section-head"><div><p class="eyebrow">Nguồn gốc thương mại</p><h3>Nhà sản xuất và thương hiệu</h3></div></div><div class="related-grid"><?php foreach ($collectorMakers as $collectorItem): if (!is_array($collectorItem) || trim((string) ($collectorItem['title'] ?? $collectorItem['name'] ?? '')) === '') continue; $collectorName = (string) ($collectorItem['title'] ?? $collectorItem['name'] ?? ''); $collectorUrl = nhk_v3_public_url($collectorItem['url'] ?? null); ?><article class="related-card"><?php if ($collectorUrl !== ''): ?><a href="<?php echo esc_url($collectorUrl); ?>"><strong><?php echo esc_html(nhk_v3_public_brand_text($collectorName)); ?></strong></a><?php else: ?><strong><?php echo esc_html(nhk_v3_public_brand_text($collectorName)); ?></strong><?php endif; ?></article><?php endforeach; ?></div></section><?php endif; ?>
      </section>
      <?php endif; ?>
      <?php endif; ?>

      <?php if ($facets !== []): ?>
      <section id="tri-thuc" class="dossier-section knowledge-dossier"><div class="section-head"><div><p class="eyebrow">Tri thức</p><h2>Những gì đã được ghi nhận</h2></div></div>
        <?php foreach ($facetLabels as $facet => $heading): $claims = is_array($facets[$facet] ?? null) ? $facets[$facet] : []; if ($claims === []) continue; ?>
        <div class="knowledge-facet"><h3><?php echo esc_html($heading); ?></h3><div class="knowledge-stack">
          <?php foreach ($claims as $claim): ?><article class="knowledge-claim"><p><?php echo esc_html(nhk_v3_public_copy((string) ($claim['text'] ?? ''))); ?></p>
            <?php $evidenceItems = is_array($claim['evidence'] ?? null) ? $claim['evidence'] : []; if ($evidenceItems !== []): ?><div class="evidence-list"><?php foreach ($evidenceItems as $evidence): ?><aside class="evidence-note"><strong><?php echo esc_html((string) ($evidence['source_title'] ?? 'Nguồn tư liệu')); ?></strong><?php if (($evidence['excerpt'] ?? '') !== ''): ?><span><?php echo esc_html((string) $evidence['excerpt']); ?></span><?php endif; ?><?php if (($evidence['locator'] ?? '') !== ''): ?><small><?php echo esc_html((string) $evidence['locator']); ?></small><?php endif; ?></aside><?php endforeach; ?></div><?php else: ?><small class="scope-note">Ghi nhận công khai hiện chưa kèm trích dẫn hiển thị.</small><?php endif; ?>
          </article><?php endforeach; ?>
        </div></div>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php if ($claimProjection !== []): ?>
      <section id="tri-thuc-chung-cu" class="dossier-section claim-ledger" aria-labelledby="claim-ledger-title">
        <div class="section-head"><div><p class="eyebrow">Tri thức &amp; chứng cứ</p><h2 id="claim-ledger-title">Tri thức về <?php echo esc_html(nhk_v3_public_brand_text((string) ($identity['name'] ?? 'hồ sơ này'))); ?></h2></div><?php if (isset($claimProjection['claim_count']) && is_numeric($claimProjection['claim_count'])): ?><span class="claim-ledger-count"><?php echo esc_html((string) $claimProjection['claim_count']); ?> ghi nhận</span><?php endif; ?></div>
        <?php if (($claimProjection['status'] ?? '') !== 'available'): ?><p class="projection-note">Tri thức chi tiết đang được cập nhật.</p>
        <?php elseif (!empty($claimProjection['sections'])): foreach ($claimProjection['sections'] as $section): if (!is_array($section) || empty($section['claims'])) continue; $sectionOpen = (int) ($section['total_count'] ?? 0) <= 12; ?><details class="claim-ledger-section" <?php echo $sectionOpen ? 'open' : ''; ?>><summary aria-expanded="<?php echo $sectionOpen ? 'true' : 'false'; ?>"><span><?php echo esc_html((string) ($section['label'] ?? 'Tri thức')); ?></span><strong><?php echo esc_html((string) ($section['total_count'] ?? $section['claim_count'] ?? 0)); ?></strong></summary><div class="claim-ledger-stack">
          <?php foreach ((array) $section['claims'] as $projectedClaim): if (!is_array($projectedClaim)) continue; ?><article class="claim-card"><p><?php echo esc_html(nhk_v3_public_copy((string) ($projectedClaim['display_text'] ?? ''))); ?></p><div class="claim-card-meta"><span class="claim-scope-badge"><?php echo esc_html(($projectedClaim['scope'] ?? 'direct') === 'direct' ? 'Áp dụng trực tiếp' : 'Liên quan có ngữ cảnh'); ?></span><span><?php echo esc_html((string) (($projectedClaim['evidence_summary']['source_count'] ?? 0) . ' nguồn · ' . ($projectedClaim['evidence_summary']['evidence_count'] ?? 0) . ' chứng cứ')); ?></span><?php if (($projectedClaim['status'] ?? '') === 'disputed'): ?><span>Đang tranh luận</span><?php elseif (($projectedClaim['status'] ?? '') === 'uncertain'): ?><span>Cần kiểm chứng</span><?php endif; ?></div><?php if (is_array($projectedClaim['source_context'] ?? null)): ?><small class="claim-context">Từ node liên quan: <?php echo esc_html((string) ($projectedClaim['source_context']['node_label'] ?? '')); ?></small><?php endif; ?></article><?php endforeach; ?>
        </div></details><?php endforeach; else: ?><p class="projection-note">Chưa có ghi nhận công khai phù hợp.</p><?php endif; ?>
      </section>
      <?php endif; ?>

      <?php if (($publishedClaimProjection['sections'] ?? []) !== []): ?>
      <section id="noi-dung-semantic" class="dossier-section published-claim-projection" aria-labelledby="published-claim-title"><div class="section-head"><div><p class="eyebrow">Nội dung tổng hợp</p><h2 id="published-claim-title">Thông tin đã được biên tập</h2></div></div>
        <?php foreach ((array) $publishedClaimProjection['sections'] as $publishedSection): if (!is_array($publishedSection) || trim((string) ($publishedSection['content'] ?? '')) === '') continue; ?><section class="published-claim-section"><h3><?php echo esc_html((string) ($publishedSection['label'] ?? '')); ?></h3><?php echo wp_kses_post((string) $publishedSection['content']); ?></section><?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php if ($gallery !== []): ?>
      <section id="hinh-anh" class="dossier-section"><div class="section-head"><div><p class="eyebrow">Hiện vật</p><h2>Hình ảnh liên quan trực tiếp</h2></div><a class="text-link" href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Mở thư viện →</a></div><div class="media-mosaic entity-gallery"><?php foreach ($gallery as $item): $image = trim((string) ($item['url'] ?? '')); if ($image === '') continue; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $identity['name'] ?? '')); ?>" loading="lazy"></figure><?php endforeach; ?></div></section>
      <?php endif; ?>

      <?php if ($hasLegacyAggregation || $hasClockTypeAggregation): $aggregationLabels = ['models' => 'Mẫu đồng hồ', 'variants' => 'Biến thể', 'movements' => 'Bộ máy', 'music' => 'Bản nhạc', 'components' => 'Linh kiện', 'classifications' => 'Phân loại', 'clock_types' => 'Nhóm đồng hồ', 'specimens' => 'Hiện vật', 'products' => 'Sản phẩm']; ?>
      <section id="cau-truc" class="dossier-section brand-aggregation"><div class="section-head"><div><p class="eyebrow">Cấu trúc thương hiệu</p><h2>Những hồ sơ đang kết nối</h2></div></div>
        <?php foreach ($aggregationLabels as $group => $heading): $items = is_array($entity['aggregation'][$group] ?? null) ? $entity['aggregation'][$group] : []; if ($group === 'classifications') $items = array_values(array_filter($items, static fn($item): bool => !is_array($item) || (string) ($item['profile_key'] ?? '') !== 'clock_type')); if ($items === []) continue; ?><div class="aggregation-block"><h3><?php echo esc_html($heading); ?></h3><div class="related-grid"><?php foreach ($items as $item): $url = nhk_v3_public_url($item['url'] ?? null); ?><article class="related-card"><?php if ($url !== ''): ?><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['name'] ?? ''))); ?></strong></a><?php else: ?><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['name'] ?? ''))); ?></strong><?php endif; ?><span class="related-type"><?php echo esc_html((string) ($item['profile_badge'] ?? '') ?: (($item['origin']['kind'] ?? '') === 'DIRECT' ? 'Liên kết trực tiếp' : 'Liên kết suy ra')); ?></span></article><?php endforeach; ?></div></div><?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php $profileOrder = is_array($view['profile']['relation_order'] ?? null) ? $view['profile']['relation_order'] : (is_array($profile['relation_order'] ?? null) ? $profile['relation_order'] : (is_array($view['profile']['section_order'] ?? null) ? $view['profile']['section_order'] : [])); $order = array_values(array_filter($profileOrder, static fn(string $group): bool => isset($relationLabels[$group]))); if ($order === []) $order = array_keys($relationLabels); foreach ($order as $group): $items = is_array($relationSections[$group] ?? null) ? $relationSections[$group] : []; $renderableItems = array_values(array_filter($items, static function ($item) use ($group): bool { if (!is_array($item)) return false; if ($group === 'media') return trim((string) ($item['image_url'] ?? '')) !== '' || trim((string) ($item['title'] ?? '')) !== ''; return nhk_v3_public_url($item['url'] ?? null) !== '' && trim((string) ($item['title'] ?? '')) !== ''; })); if ($renderableItems === []) continue; $items = $renderableItems; $heading = $relationLabels[$group] ?? 'Liên quan'; ?>
      <section class="dossier-section relation-section relation-group-<?php echo esc_attr($group); ?>"><div class="section-head"><div><p class="eyebrow">Liên quan</p><h2><?php echo esc_html($heading); ?></h2></div></div>
        <?php if ($group === 'videos'): ?><div class="video-card-grid"><?php foreach ($items as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $thumb = trim((string) ($item['thumbnail_url'] ?? '')) ?: $fallback; $thumbMeta = is_array($item['thumbnail'] ?? null) ? $item['thumbnail'] : []; ?><a class="video-card" href="<?php echo esc_url($url); ?>"><span class="visual-frame video-thumb"><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"<?php echo !empty($thumbMeta['width']) ? ' width="' . esc_attr((string) $thumbMeta['width']) . '"' : ''; ?><?php echo !empty($thumbMeta['height']) ? ' height="' . esc_attr((string) $thumbMeta['height']) . '"' : ''; ?>><span class="play-mark" aria-hidden="true">▶</span></span><span class="visual-card-body"><small><?php echo esc_html(($item['origin']['kind'] ?? '') === 'DIRECT' ? 'Liên quan trực tiếp' : 'Mở rộng từ quan hệ nền'); ?></small><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></span></a><?php endforeach; ?></div>
        <?php elseif ($group === 'media'): ?><div class="media-mosaic related-media-grid"><?php foreach ($items as $item): $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $item['title'] ?? '')); ?>" loading="lazy"><figcaption><?php echo esc_html((string) ($item['title'] ?? 'Hình ảnh')); ?> · <?php echo esc_html(($item['origin']['kind'] ?? '') === 'DIRECT' ? 'liên quan trực tiếp' : 'liên quan mở rộng'); ?></figcaption></figure><?php endforeach; ?></div>
        <?php else: ?><div class="related-grid"><?php foreach ($items as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type"><?php echo esc_html(($item['origin']['kind'] ?? '') === 'DIRECT' ? 'Liên quan trực tiếp' : 'Mở rộng từ quan hệ nền'); ?></span><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong><?php $via = is_array($item['origin']['via_types'] ?? null) ? $item['origin']['via_types'] : []; if ($via !== []): ?><small>Qua <?php echo esc_html(implode(' → ', array_map('nhk_v3_public_type', $via))); ?></small><?php endif; ?></a><?php endforeach; ?></div><?php endif; ?>
      </section>
      <?php endforeach; ?>

      <?php if ($dossier === null && array_filter($relatedGroups)): ?>
      <section class="dossier-section"><div class="section-head"><div><p class="eyebrow">Liên quan</p><h2>Nội dung có liên hệ</h2></div></div><div class="related-grid"><?php foreach (['entities','articles','videos'] as $group): foreach ((array) ($relatedGroups[$group] ?? []) as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type"><?php echo esc_html($nhkReaderType($item)); ?></span><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></a><?php endforeach; endforeach; ?></div></section>
      <?php endif; ?>
    </div>

    <aside class="context-rail" aria-label="Thông tin liên quan">
      <?php get_template_part('template-parts/presentation/visual-rail', null, ['view' => $view]); ?>
      <?php if ($profileKey !== 'clock_type' && is_array($view['hierarchy'] ?? null)): ?><div class="context-box"><p class="eyebrow">Cấu trúc</p><?php get_template_part('template-parts/presentation/hierarchy-nav', null, ['hierarchy' => $view['hierarchy']]); ?></div><?php endif; ?>
      <div class="context-box"><p class="eyebrow">Trong hồ sơ này</p><nav><?php if ($visiblePayload !== []): ?><a href="#ho-so">Thông tin định danh</a><?php endif; ?><?php if ($facets !== []): ?><a href="#tri-thuc">Tri thức</a><?php endif; ?><?php if ($claimProjection !== []): ?><a href="#tri-thuc-chung-cu">Tri thức &amp; chứng cứ</a><?php endif; ?><?php if ($gallery !== []): ?><a href="#hinh-anh">Hình ảnh</a><?php endif; ?><?php if ($hasLegacyAggregation): ?><a href="#cau-truc">Cấu trúc liên quan</a><?php elseif ($hasClockTypeAggregation): ?><a href="#cau-truc">Cấu trúc liên quan</a><?php endif; ?></nav></div>
      <?php if ($dictionaryTerms !== []): ?><div class="context-box"><p class="eyebrow">Từ điển liên quan</p><ul class="context-list"><?php foreach ($dictionaryTerms as $term): $url = nhk_v3_public_url($term['url'] ?? null); if ($url === '') continue; ?><li><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html((string) ($term['title'] ?? '')); ?></strong><?php if (($term['description'] ?? '') !== ''): ?><span><?php echo esc_html(wp_trim_words((string) $term['description'], 14)); ?></span><?php endif; ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="context-box"><p class="eyebrow">Khám phá tiếp</p><nav><a href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Kho hình ảnh</a><a href="<?php echo esc_url(home_url('/video/')); ?>">Kho video</a><a href="<?php echo esc_url(home_url('/tu-dien/')); ?>">Từ điển</a><a href="<?php echo esc_url(home_url('/tri-thuc/')); ?>">Bài nghiên cứu</a></nav></div>
    </aside>
  </div>

<?php elseif (is_array($context) && is_array($context['archive'] ?? null)): $archive = $context['archive']; $archiveItems = array_values(array_filter((array) ($archive['items'] ?? []), static fn($item): bool => is_array($item) && nhk_v3_public_url($item['url'] ?? null) !== '')); $archiveUrl = (string) ($context['archive_url'] ?? home_url($archivePaths[$type] ?? '/')); $archiveLabel = $profileKey === 'clock_type' ? 'Nhóm đồng hồ' : $label; $archiveHeading = $profileKey === 'clock_type' ? 'Khám phá theo nhóm đồng hồ' : 'Khám phá ' . strtolower($label); $archiveSummary = $profileKey === 'clock_type' ? 'Duyệt theo loại đồng hồ để hiểu mỗi nhóm có hình thức, công năng, bối cảnh và dấu nhận diện riêng.' : 'Mỗi hồ sơ giúp bạn hiểu một đối tượng trong kho: nó là gì, được đặt trong bối cảnh nào và liên hệ với những tư liệu nào.'; ?>
  <header class="archive-intro entity-archive-intro"><p class="eyebrow"><?php echo esc_html($label); ?></p><h1><?php echo esc_html($archiveHeading); ?></h1><p class="archive-summary"><?php echo esc_html($archiveSummary); ?></p><form class="entity-filter" method="get" action="<?php echo esc_url($archiveUrl); ?>"><label class="screen-reader-text" for="nhk-entity-q">Tìm trong <?php echo esc_attr(strtolower($label)); ?></label><input id="nhk-entity-q" name="nhk_entity_q" type="search" value="<?php echo esc_attr((string) ($archive['query'] ?? '')); ?>" placeholder="Tìm <?php echo esc_attr(strtolower($label)); ?>..."><button type="submit">Tìm</button></form></header>
  <?php if (($archive['available'] ?? true) === false): ?><div class="empty"><h2>Dữ liệu chưa sẵn sàng</h2><p>Kho hồ sơ hiện không thể truy vấn.</p></div><?php elseif ($archiveItems !== []): ?><div class="entity-grid visual-entity-grid"><?php foreach ($archiveItems as $item): get_template_part('template-parts/presentation/entity-card', null, ['item' => ['title' => $item['name'] ?? '', 'url' => $item['url'] ?? null, 'image_url' => $item['media']['representative']['url'] ?? null, 'image_alt' => $item['media']['representative']['alt'] ?? ($item['name'] ?? ''), 'description' => $item['description'] ?? '', 'profile_label' => $item['profile_label'] ?? $label]]); endforeach; ?></div><?php else: ?><div class="empty"><h2>Chưa có hồ sơ phù hợp</h2><p>Thử từ khóa khác hoặc quay lại sau khi hồ sơ được bổ sung.</p></div><?php endif; ?>
  <?php $pages = (int) ceil((int) ($archive['total'] ?? 0) / max(1, (int) ($archive['per_page'] ?? 1))); if ($pages > 1): ?><nav class="entity-pagination" aria-label="Phân trang <?php echo esc_attr($archiveLabel); ?>"><?php for ($i = 1; $i <= $pages; $i++): $url = rtrim($archiveUrl, '/') . ($i > 1 ? '/page/' . $i . '/' : '/'); if (($archive['query'] ?? '') !== '') $url = add_query_arg('nhk_entity_q', $archive['query'], $url); $current = $i === (int) ($archive['page'] ?? 1); ?><a class="<?php echo $current ? 'current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $i); ?></a><?php endfor; ?></nav><?php endif; ?>
<?php else: ?><div class="empty"><h1>Không thể tải hồ sơ</h1><p>Trang này hiện chưa sẵn sàng.</p></div><?php endif; ?>
</main>
<?php get_footer(); ?>
