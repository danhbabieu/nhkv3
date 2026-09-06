<?php
/* public seo_projection remains the canonical URL/indexability owner. */
$context = $GLOBALS['nhk_core_entity_context'] ?? null;
$labels = ['brand' => 'Thương hiệu', 'model' => 'Mẫu đồng hồ', 'variant' => 'Biến thể', 'movement' => 'Bộ máy', 'music' => 'Bản nhạc', 'component' => 'Linh kiện', 'classification' => 'Phân loại', 'specimen' => 'Hiện vật', 'product' => 'Sản phẩm'];
$archivePaths = ['brand' => '/thuong-hieu/', 'model' => '/mau/', 'variant' => '/bien-the/', 'movement' => '/bo-may/', 'music' => '/ban-nhac/', 'component' => '/linh-kien/', 'classification' => '/phan-loai/', 'specimen' => '/hien-vat/', 'product' => '/san-pham/'];
$facetLabels = ['identity' => 'Định danh', 'chronology' => 'Niên đại', 'recognition' => 'Dấu nhận diện', 'configuration' => 'Cấu hình đã ghi nhận', 'movement' => 'Kết cấu & bộ máy', 'music' => 'Chuông & nhạc', 'component' => 'Linh kiện', 'provenance' => 'Nguồn gốc & tư liệu', 'domestic_cultural' => 'Bối cảnh sử dụng', 'rarity_frequency' => 'Mức độ gặp', 'specimen_observation' => 'Quan sát hiện vật'];
$relationLabels = ['brands' => 'Thương hiệu', 'models' => 'Mẫu đồng hồ', 'variants' => 'Biến thể', 'movements' => 'Bộ máy', 'music' => 'Bản nhạc', 'components' => 'Linh kiện', 'classifications' => 'Phân loại', 'specimens' => 'Hiện vật', 'products' => 'Sản phẩm', 'articles' => 'Bài viết', 'media' => 'Hình ảnh', 'videos' => 'Video'];
$profiles = [
    'brand' => ['models','variants','movements','music','components','classifications','specimens','products','articles','media','videos'],
    'model' => ['brands','variants','movements','music','components','classifications','specimens','products','articles','media','videos'],
    'variant' => ['brands','models','movements','music','components','classifications','specimens','products','articles','media','videos'],
    'movement' => ['variants','models','brands','music','components','classifications','specimens','products','articles','media','videos'],
    'music' => ['variants','models','brands','movements','components','specimens','products','articles','media','videos'],
    'component' => ['variants','models','brands','movements','music','specimens','products','articles','media','videos'],
    'classification' => ['brands','models','variants','movements','specimens','products','articles','media','videos'],
    'specimen' => ['brands','models','variants','movements','music','components','classifications','articles','media','videos'],
    'product' => ['brands','models','variants','movements','music','components','classifications','articles','media','videos'],
];
$type = is_array($context) ? (string) ($context['type'] ?? '') : '';
$label = $labels[$type] ?? 'Khám phá';
$fallback = get_theme_file_uri('/assets/default-archive.svg');
$nhkReaderType = static fn(array $item): string => nhk_v3_public_type((string) ($item['type'] ?? ''));
get_header();
?>
<main id="main-content" class="site-main entity-shell entity-v2">
<?php if (is_array($context) && ($context['mode'] ?? '') === 'detail' && is_array($context['entity'] ?? null)): $entity = $context['entity'];
    $dossier = is_array($entity['dossier'] ?? null) && ($entity['dossier']['status'] ?? '') === 'AVAILABLE' ? $entity['dossier'] : null;
    $profile = is_array($entity['dossier']['profile'] ?? null) ? $entity['dossier']['profile'] : [];
    $identity = is_array($profile['identity'] ?? null) ? $profile['identity'] : (is_array($dossier['identity'] ?? null) ? $dossier['identity'] : ['type' => $type, 'name' => (string) ($entity['name'] ?? ''), 'payload' => is_array($entity['payload'] ?? null) ? $entity['payload'] : [], 'url' => $entity['url'] ?? null]);
    $payload = is_array($identity['payload'] ?? null) ? $identity['payload'] : [];
    $visiblePayload = [];
    foreach ($payload as $key => $value) {
        if (in_array((string) $key, ['description', 'summary'], true) || str_ends_with((string) $key, '_uuid')) continue;
        if ($value === null || $value === '' || (is_array($value) && $value === [])) continue;
        $visiblePayload[$key] = $value;
    }
    $knowledge = is_array($profile['knowledge'] ?? null) ? $profile['knowledge'] : (is_array($dossier['knowledge'] ?? null) ? $dossier['knowledge'] : (is_array($entity['knowledge'] ?? null) ? $entity['knowledge'] : []));
    $facets = is_array($knowledge['facets'] ?? null) ? $knowledge['facets'] : [];
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
?>
  <p class="breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">NHK</a> <span>/</span> <a href="<?php echo esc_url(home_url($archivePaths[$type] ?? '/')); ?>"><?php echo esc_html($label); ?></a></p>

  <header class="entity-dossier-hero">
    <figure class="entity-hero-visual"><img src="<?php echo esc_url($heroImage); ?>" alt="<?php echo esc_attr((string) ($primary['alt'] ?? $identity['name'] ?? '')); ?>" loading="eager" fetchpriority="high"></figure>
    <div class="entity-hero-copy">
      <p class="eyebrow"><?php echo esc_html($label); ?></p>
      <h1><?php echo esc_html(nhk_v3_public_brand_text((string) ($identity['name'] ?? ''))); ?></h1>
      <?php $description = trim((string) (($payload['description'] ?? '') ?: ($payload['summary'] ?? ''))); if ($description !== ''): ?><p class="entity-lead"><?php echo esc_html(nhk_v3_public_copy($description)); ?></p><?php else: ?><p class="entity-lead">Hồ sơ tổng hợp từ định danh, tri thức, quan hệ, hình ảnh và video đang có trong kho.</p><?php endif; ?>
      <div class="dossier-stats"><span><strong><?php echo esc_html((string) count($gallery)); ?></strong> hình ảnh</span><span><strong><?php echo esc_html((string) ($knowledge['claim_count'] ?? 0)); ?></strong> ghi nhận tri thức</span><span><strong><?php echo esc_html((string) ($coverage['video_count'] ?? count($relationSections['videos'] ?? []))); ?></strong> video</span></div>
    </div>
  </header>

  <div class="semantic-layout">
    <div class="semantic-main">
      <?php if ($visiblePayload !== []): ?>
      <section id="ho-so" class="dossier-section"><div class="section-head"><div><p class="eyebrow">Hồ sơ</p><h2>Thông tin định danh</h2></div></div><dl class="entity-facts"><?php foreach ($visiblePayload as $key => $value): ?><dt><?php echo esc_html(nhk_v3_public_label((string) $key)); ?></dt><dd><?php echo esc_html(nhk_v3_public_value($value)); ?></dd><?php endforeach; ?></dl></section>
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

      <?php if ($gallery !== []): ?>
      <section id="hinh-anh" class="dossier-section"><div class="section-head"><div><p class="eyebrow">Hiện vật</p><h2>Hình ảnh liên quan trực tiếp</h2></div><a class="text-link" href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Mở thư viện →</a></div><div class="media-mosaic entity-gallery"><?php foreach ($gallery as $item): $image = trim((string) ($item['url'] ?? '')); if ($image === '') continue; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $identity['name'] ?? '')); ?>" loading="lazy"></figure><?php endforeach; ?></div></section>
      <?php endif; ?>

      <?php if ($hasLegacyAggregation): $aggregationLabels = ['models' => 'Mẫu đồng hồ', 'variants' => 'Biến thể', 'movements' => 'Bộ máy', 'music' => 'Bản nhạc', 'components' => 'Linh kiện', 'classifications' => 'Phân loại', 'specimens' => 'Hiện vật', 'products' => 'Sản phẩm']; ?>
      <section id="cau-truc" class="dossier-section brand-aggregation"><div class="section-head"><div><p class="eyebrow">Cấu trúc thương hiệu</p><h2>Những hồ sơ đang kết nối</h2></div></div>
        <?php foreach ($aggregationLabels as $group => $heading): $items = is_array($entity['aggregation'][$group] ?? null) ? $entity['aggregation'][$group] : []; if ($items === []) continue; ?><div class="aggregation-block"><h3><?php echo esc_html($heading); ?></h3><div class="related-grid"><?php foreach ($items as $item): $url = nhk_v3_public_url($item['url'] ?? null); ?><article class="related-card"><?php if ($url !== ''): ?><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['name'] ?? ''))); ?></strong></a><?php else: ?><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['name'] ?? ''))); ?></strong><?php endif; ?><span class="related-type"><?php echo esc_html(($item['origin']['kind'] ?? '') === 'DIRECT' ? 'Liên kết trực tiếp' : 'Liên kết suy ra'); ?></span></article><?php endforeach; ?></div></div><?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php $profileOrder = is_array($profile['relation_order'] ?? null) ? $profile['relation_order'] : (is_array($profile['section_order'] ?? null) ? $profile['section_order'] : []); $order = array_values(array_filter($profileOrder, static fn(string $group): bool => isset($relationLabels[$group]))); if ($order === []) $order = $profiles[$type] ?? array_keys($relationLabels); foreach ($order as $group): $items = is_array($relationSections[$group] ?? null) ? $relationSections[$group] : []; if ($items === []) continue; $heading = $relationLabels[$group] ?? 'Liên quan'; ?>
      <section class="dossier-section relation-section relation-group-<?php echo esc_attr($group); ?>"><div class="section-head"><div><p class="eyebrow">Liên quan</p><h2><?php echo esc_html($heading); ?></h2></div></div>
        <?php if ($group === 'videos'): ?><div class="video-card-grid"><?php foreach ($items as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $thumb = trim((string) ($item['thumbnail_url'] ?? '')) ?: $fallback; ?><a class="video-card" href="<?php echo esc_url($url); ?>"><span class="visual-frame video-thumb"><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"><span class="play-mark" aria-hidden="true">▶</span></span><span class="visual-card-body"><small><?php echo esc_html(($item['origin']['kind'] ?? '') === 'DIRECT' ? 'Liên quan trực tiếp' : 'Mở rộng từ quan hệ nền'); ?></small><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></span></a><?php endforeach; ?></div>
        <?php elseif ($group === 'media'): ?><div class="media-mosaic related-media-grid"><?php foreach ($items as $item): $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $item['title'] ?? '')); ?>" loading="lazy"><figcaption><?php echo esc_html((string) ($item['title'] ?? 'Hình ảnh')); ?> · <?php echo esc_html(($item['origin']['kind'] ?? '') === 'DIRECT' ? 'liên quan trực tiếp' : 'liên quan mở rộng'); ?></figcaption></figure><?php endforeach; ?></div>
        <?php else: ?><div class="related-grid"><?php foreach ($items as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type"><?php echo esc_html(($item['origin']['kind'] ?? '') === 'DIRECT' ? 'Liên quan trực tiếp' : 'Mở rộng từ quan hệ nền'); ?></span><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong><?php $via = is_array($item['origin']['via_types'] ?? null) ? $item['origin']['via_types'] : []; if ($via !== []): ?><small>Qua <?php echo esc_html(implode(' → ', array_map('nhk_v3_public_type', $via))); ?></small><?php endif; ?></a><?php endforeach; ?></div><?php endif; ?>
      </section>
      <?php endforeach; ?>

      <?php if ($dossier === null && array_filter($relatedGroups)): ?>
      <section class="dossier-section"><div class="section-head"><div><p class="eyebrow">Liên quan</p><h2>Nội dung có liên hệ</h2></div></div><div class="related-grid"><?php foreach (['entities','articles','videos'] as $group): foreach ((array) ($relatedGroups[$group] ?? []) as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type"><?php echo esc_html($nhkReaderType($item)); ?></span><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></a><?php endforeach; endforeach; ?></div></section>
      <?php endif; ?>
    </div>

    <aside class="context-rail" aria-label="Thông tin liên quan">
      <div class="context-box"><p class="eyebrow">Trong hồ sơ này</p><nav><?php if ($visiblePayload !== []): ?><a href="#ho-so">Thông tin định danh</a><?php endif; ?><?php if ($facets !== []): ?><a href="#tri-thuc">Tri thức</a><?php endif; ?><?php if ($gallery !== []): ?><a href="#hinh-anh">Hình ảnh</a><?php endif; ?><?php if ($hasLegacyAggregation): ?><a href="#cau-truc">Cấu trúc liên quan</a><?php endif; ?></nav></div>
      <?php if ($dictionaryTerms !== []): ?><div class="context-box"><p class="eyebrow">Từ điển liên quan</p><ul class="context-list"><?php foreach ($dictionaryTerms as $term): $url = nhk_v3_public_url($term['url'] ?? null); if ($url === '') continue; ?><li><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html((string) ($term['title'] ?? '')); ?></strong><?php if (($term['description'] ?? '') !== ''): ?><span><?php echo esc_html(wp_trim_words((string) $term['description'], 14)); ?></span><?php endif; ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="context-box"><p class="eyebrow">Khám phá tiếp</p><nav><a href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Kho hình ảnh</a><a href="<?php echo esc_url(home_url('/video/')); ?>">Kho video</a><a href="<?php echo esc_url(home_url('/tu-dien/')); ?>">Từ điển</a><a href="<?php echo esc_url(home_url('/tri-thuc/')); ?>">Bài nghiên cứu</a></nav></div>
    </aside>
  </div>

<?php elseif (is_array($context) && is_array($context['archive'] ?? null)): $archive = $context['archive']; ?>
  <header class="archive-intro entity-archive-intro"><p class="eyebrow"><?php echo esc_html($label); ?></p><h1>Khám phá <?php echo esc_html(strtolower($label)); ?></h1><p class="archive-summary">Duyệt các hồ sơ đang hoạt động; ảnh đại diện đã được liên kết sẽ được ưu tiên hiển thị.</p><form class="entity-filter" method="get" action="<?php echo esc_url(home_url($archivePaths[$type] ?? '/')); ?>"><label class="screen-reader-text" for="nhk-entity-q">Tìm trong <?php echo esc_attr(strtolower($label)); ?></label><input id="nhk-entity-q" name="nhk_entity_q" type="search" value="<?php echo esc_attr((string) ($archive['query'] ?? '')); ?>" placeholder="Tìm <?php echo esc_attr(strtolower($label)); ?>..."><button type="submit">Tìm</button></form></header>
  <?php if (($archive['available'] ?? true) === false): ?><div class="empty"><h2>Dữ liệu chưa sẵn sàng</h2><p>Kho hồ sơ hiện không thể truy vấn.</p></div><?php elseif (!empty($archive['items'])): ?><div class="entity-grid visual-entity-grid"><?php foreach ($archive['items'] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $image = trim((string) ($item['media']['representative']['url'] ?? '')) ?: $fallback; ?><article class="entity-card visual-entity-card"><a class="entity-card-image" href="<?php echo esc_url($url); ?>"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['media']['representative']['alt'] ?? '')); ?>" loading="lazy"></a><div class="entity-card-body"><p class="eyebrow"><?php echo esc_html($label); ?></p><h2><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['name'] ?? ''))); ?></a></h2></div></article><?php endforeach; ?></div><?php else: ?><div class="empty"><h2>Chưa có hồ sơ phù hợp</h2><p>Thử từ khóa khác hoặc quay lại sau khi hồ sơ được bổ sung.</p></div><?php endif; ?>
  <?php $pages = (int) ceil((int) ($archive['total'] ?? 0) / max(1, (int) ($archive['per_page'] ?? 1))); if ($pages > 1): ?><nav class="entity-pagination" aria-label="Phân trang <?php echo esc_attr($label); ?>"><?php for ($i = 1; $i <= $pages; $i++): $url = rtrim((string) home_url($archivePaths[$type] ?? '/'), '/') . ($i > 1 ? '/page/' . $i . '/' : '/'); if (($archive['query'] ?? '') !== '') $url = add_query_arg('nhk_entity_q', $archive['query'], $url); $current = $i === (int) ($archive['page'] ?? 1); ?><a class="<?php echo $current ? 'current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $i); ?></a><?php endfor; ?></nav><?php endif; ?>
<?php else: ?><div class="empty"><h1>Không thể tải hồ sơ</h1><p>Trang này hiện chưa sẵn sàng.</p></div><?php endif; ?>
</main>
<?php get_footer(); ?>
