<?php
$context = $GLOBALS['nhk_core_entity_context'] ?? null;
$labels = ['brand' => 'Thương hiệu', 'model' => 'Mẫu đồng hồ', 'variant' => 'Biến thể', 'movement' => 'Bộ máy', 'music' => 'Bản nhạc', 'component' => 'Linh kiện', 'classification' => 'Phân loại', 'specimen' => 'Hiện vật', 'product' => 'Sản phẩm'];
$archivePaths = ['brand' => '/thuong-hieu/', 'model' => '/mau/', 'variant' => '/bien-the/', 'movement' => '/bo-may/', 'music' => '/ban-nhac/', 'component' => '/linh-kien/', 'classification' => '/phan-loai/', 'specimen' => '/hien-vat/', 'product' => '/san-pham/'];
$facetLabels = ['identity' => 'Định danh', 'chronology' => 'Niên đại', 'recognition' => 'Dấu nhận diện', 'configuration' => 'Cấu hình đã ghi nhận', 'movement' => 'Kết cấu & bộ máy', 'music' => 'Chuông & nhạc', 'component' => 'Linh kiện', 'provenance' => 'Nguồn gốc & tư liệu', 'domestic_cultural' => 'Bối cảnh sử dụng', 'rarity_frequency' => 'Mức độ gặp', 'specimen_observation' => 'Quan sát hiện vật'];
$type = is_array($context) ? (string) ($context['type'] ?? '') : '';
$label = $labels[$type] ?? 'Khám phá';
$fallback = get_theme_file_uri('/assets/default-archive.svg');
get_header();
?>
<main id="main-content" class="site-main entity-shell entity-v2">
<?php if (is_array($context) && ($context['mode'] ?? '') === 'detail' && is_array($context['entity'] ?? null)): $entity = $context['entity'];
  $media = is_array($entity['media'] ?? null) ? $entity['media'] : [];
  $representative = is_array($media['representative'] ?? null) ? $media['representative'] : null;
  $heroImage = trim((string) ($representative['url'] ?? '')) ?: $fallback;
  $gallery = is_array($media['gallery'] ?? null) ? $media['gallery'] : [];
  $knowledge = is_array($entity['knowledge'] ?? null) ? $entity['knowledge'] : [];
  $facets = is_array($knowledge['facets'] ?? null) ? $knowledge['facets'] : [];
  $related = is_array($entity['related'] ?? null) ? $entity['related'] : [];
  $lexicalText = (string) ($entity['name'] ?? '') . ' ' . wp_json_encode($entity['payload'] ?? []);
  foreach ($facets as $claims) foreach ((array) $claims as $claim) if (is_array($claim)) $lexicalText .= ' ' . (string) ($claim['text'] ?? '');
  $dictionaryTerms = apply_filters('nhk_v3_public_dictionary_terms_for_text', [], $lexicalText);
  $dictionaryTerms = is_array($dictionaryTerms) ? $dictionaryTerms : [];
  $direct = ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
  $derived = ['entities' => [], 'articles' => [], 'media' => [], 'videos' => []];
  foreach ($direct as $group => $_) foreach ((array) ($related[$group] ?? []) as $item) if (is_array($item)) { $target = (($item['relationship_class'] ?? 'DIRECT') === 'DERIVED') ? 'derived' : 'direct'; ${$target}[$group][] = $item; }
?>
  <p class="breadcrumb"><a href="<?php echo esc_url(home_url('/')); ?>">NHK</a> <span>/</span> <a href="<?php echo esc_url(home_url($archivePaths[$type] ?? '/')); ?>"><?php echo esc_html($label); ?></a></p>

  <header class="entity-dossier-hero">
    <figure class="entity-hero-visual"><img src="<?php echo esc_url($heroImage); ?>" alt="<?php echo esc_attr((string) ($representative['alt'] ?? $entity['name'] ?? '')); ?>" loading="eager" fetchpriority="high"></figure>
    <div class="entity-hero-copy"><p class="eyebrow"><?php echo esc_html($label); ?></p><h1><?php echo esc_html(nhk_v3_public_brand_text((string) ($entity['name'] ?? ''))); ?></h1>
      <?php $description = trim((string) (($entity['payload']['description'] ?? '') ?: ($entity['payload']['summary'] ?? ''))); if ($description !== ''): ?><p class="entity-lead"><?php echo esc_html(nhk_v3_public_brand_text($description)); ?></p><?php else: ?><p class="entity-lead">Hồ sơ tổng hợp từ dữ liệu định danh, tri thức, quan hệ, hình ảnh và video đang có trong kho.</p><?php endif; ?>
      <div class="dossier-stats"><span><strong><?php echo esc_html((string) count($gallery)); ?></strong> hình ảnh</span><span><strong><?php echo esc_html((string) ($knowledge['claim_count'] ?? 0)); ?></strong> ghi nhận tri thức</span><span><strong><?php echo esc_html((string) count($direct['videos'])); ?></strong> video trực tiếp</span></div>
    </div>
  </header>

  <div class="semantic-layout">
    <div class="semantic-main">
      <?php if (!empty($entity['payload'])): ?>
      <section id="ho-so" class="dossier-section"><div class="section-head"><div><p class="eyebrow">Hồ sơ</p><h2>Thông tin định danh</h2></div></div><dl class="entity-facts"><?php foreach ($entity['payload'] as $key => $value): if (in_array((string) $key, ['description','summary'], true)) continue; ?><dt><?php echo esc_html(nhk_v3_public_label((string) $key)); ?></dt><dd><?php echo esc_html(nhk_v3_public_value($value)); ?></dd><?php endforeach; ?></dl></section>
      <?php endif; ?>

      <?php if ($facets !== []): ?>
      <section id="tri-thuc" class="dossier-section knowledge-dossier"><div class="section-head"><div><p class="eyebrow">Tri thức</p><h2>Những gì đã được ghi nhận</h2></div></div>
        <?php foreach ($facetLabels as $facet => $heading): $claims = is_array($facets[$facet] ?? null) ? $facets[$facet] : []; if ($claims === []) continue; ?>
        <div class="knowledge-facet"><h3><?php echo esc_html($heading); ?></h3><div class="knowledge-stack">
          <?php foreach ($claims as $claim): ?><article class="knowledge-claim"><p><?php echo esc_html(nhk_v3_public_brand_text((string) ($claim['text'] ?? ''))); ?></p>
            <?php $evidenceItems = is_array($claim['evidence'] ?? null) ? $claim['evidence'] : []; if ($evidenceItems !== []): ?><div class="evidence-list"><?php foreach ($evidenceItems as $evidence): ?><aside class="evidence-note"><strong><?php echo esc_html((string) ($evidence['source_title'] ?? 'Nguồn tư liệu')); ?></strong><?php if (($evidence['excerpt'] ?? '') !== ''): ?><span><?php echo esc_html((string) $evidence['excerpt']); ?></span><?php endif; ?><?php if (($evidence['locator'] ?? '') !== ''): ?><small><?php echo esc_html((string) $evidence['locator']); ?></small><?php endif; ?></aside><?php endforeach; ?></div><?php else: ?><small class="scope-note">Ghi nhận công khai hiện chưa kèm trích dẫn hiển thị.</small><?php endif; ?>
          </article><?php endforeach; ?>
        </div></div>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php if ($gallery !== []): ?>
      <section id="hinh-anh" class="dossier-section"><div class="section-head"><div><p class="eyebrow">Hiện vật</p><h2>Hình ảnh liên quan</h2></div><a class="text-link" href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Mở thư viện →</a></div><div class="media-mosaic entity-gallery"><?php foreach ($gallery as $item): $image = trim((string) ($item['url'] ?? '')); if ($image === '') continue; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $entity['name'] ?? '')); ?>" loading="lazy"></figure><?php endforeach; ?></div></section>
      <?php endif; ?>

      <?php if ($type === 'brand' && is_array($entity['aggregation'] ?? null)): $aggregationLabels = ['models' => 'Mẫu đồng hồ', 'variants' => 'Biến thể', 'movements' => 'Bộ máy', 'music' => 'Bản nhạc', 'components' => 'Linh kiện', 'classifications' => 'Phân loại', 'specimens' => 'Hiện vật', 'products' => 'Sản phẩm']; ?>
      <section id="cau-truc" class="dossier-section brand-aggregation"><div class="section-head"><div><p class="eyebrow">Cấu trúc thương hiệu</p><h2>Những hồ sơ đang kết nối</h2></div></div>
        <?php foreach ($aggregationLabels as $group => $heading): $items = is_array($entity['aggregation'][$group] ?? null) ? $entity['aggregation'][$group] : []; if ($items === []) continue; ?><div class="aggregation-block"><h3><?php echo esc_html($heading); ?></h3><div class="related-grid"><?php foreach ($items as $item): $url = nhk_v3_public_url($item['url'] ?? null); ?><article class="related-card"><?php if ($url !== ''): ?><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['name'] ?? ''))); ?></strong></a><?php else: ?><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['name'] ?? ''))); ?></strong><?php endif; ?><span class="related-type"><?php echo esc_html(($item['origin']['kind'] ?? '') === 'DIRECT' ? 'Liên kết trực tiếp' : 'Liên kết suy ra'); ?></span></article><?php endforeach; ?></div></div><?php endforeach; ?>
      </section>
      <?php endif; ?>

      <?php foreach ([['key' => 'direct', 'data' => $direct, 'eyebrow' => 'Quan hệ cấp 1', 'title' => 'Liên quan trực tiếp'], ['key' => 'derived', 'data' => $derived, 'eyebrow' => 'Quan hệ mở rộng', 'title' => 'Mở rộng từ quan hệ nền']] as $relationBlock): $groups = $relationBlock['data']; if (!array_filter($groups)) continue; ?>
      <section class="dossier-section relation-section relation-<?php echo esc_attr($relationBlock['key']); ?>"><div class="section-head"><div><p class="eyebrow"><?php echo esc_html($relationBlock['eyebrow']); ?></p><h2><?php echo esc_html($relationBlock['title']); ?></h2></div></div>
        <?php if ($groups['entities'] !== []): ?><div class="relation-group"><h3>Hồ sơ</h3><div class="related-grid"><?php foreach ($groups['entities'] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type"><?php echo esc_html(nhk_v3_public_type((string) ($item['type'] ?? ''))); ?></span><strong><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['title'] ?? ''))); ?></strong></a><?php endforeach; ?></div></div><?php endif; ?>
        <?php if ($groups['articles'] !== []): ?><div class="relation-group"><h3>Bài viết</h3><div class="related-grid"><?php foreach ($groups['articles'] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; ?><a class="related-card" href="<?php echo esc_url($url); ?>"><span class="related-type">Bài viết</span><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></a><?php endforeach; ?></div></div><?php endif; ?>
        <?php if ($groups['media'] !== []): ?><div class="relation-group"><h3>Hình ảnh</h3><div class="media-mosaic related-media-grid"><?php foreach ($groups['media'] as $item): $image = trim((string) ($item['image_url'] ?? '')) ?: $fallback; ?><figure class="media-figure"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['alt'] ?? $item['title'] ?? '')); ?>" loading="lazy"><figcaption><?php echo esc_html((string) ($item['title'] ?? 'Hình ảnh')); ?></figcaption></figure><?php endforeach; ?></div></div><?php endif; ?>
        <?php if ($groups['videos'] !== []): ?><div class="relation-group"><h3>Video</h3><div class="video-card-grid"><?php foreach ($groups['videos'] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $thumb = trim((string) ($item['thumbnail_url'] ?? '')) ?: $fallback; ?><a class="video-card" href="<?php echo esc_url($url); ?>"><span class="visual-frame video-thumb"><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"><span class="play-mark" aria-hidden="true">▶</span></span><span class="visual-card-body"><small>Video</small><strong><?php echo esc_html((string) ($item['title'] ?? '')); ?></strong></span></a><?php endforeach; ?></div></div><?php endif; ?>
      </section>
      <?php endforeach; ?>
    </div>

    <aside class="context-rail" aria-label="Thông tin liên quan">
      <div class="context-box"><p class="eyebrow">Trong hồ sơ này</p><nav><a href="#ho-so">Thông tin định danh</a><?php if ($facets !== []): ?><a href="#tri-thuc">Tri thức</a><?php endif; ?><?php if ($gallery !== []): ?><a href="#hinh-anh">Hình ảnh</a><?php endif; ?><?php if ($type === 'brand' && is_array($entity['aggregation'] ?? null)): ?><a href="#cau-truc">Cấu trúc liên quan</a><?php endif; ?></nav></div>
      <?php if ($dictionaryTerms !== []): ?><div class="context-box"><p class="eyebrow">Từ điển liên quan</p><ul class="context-list"><?php foreach ($dictionaryTerms as $term): $url = nhk_v3_public_url($term['url'] ?? null); if ($url === '') continue; ?><li><a href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html((string) ($term['title'] ?? '')); ?></strong><?php if (($term['description'] ?? '') !== ''): ?><span><?php echo esc_html(wp_trim_words((string) $term['description'], 14)); ?></span><?php endif; ?></a></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="context-box"><p class="eyebrow">Khám phá tiếp</p><nav><a href="<?php echo esc_url(home_url('/thu-vien/')); ?>">Kho hình ảnh</a><a href="<?php echo esc_url(home_url('/video/')); ?>">Kho video</a><a href="<?php echo esc_url(home_url('/tu-dien/')); ?>">Từ điển</a><a href="<?php echo esc_url(home_url('/tri-thuc/')); ?>">Bài nghiên cứu</a></nav></div>
    </aside>
  </div>

<?php elseif (is_array($context) && is_array($context['archive'] ?? null)): $archive = $context['archive']; ?>
  <header class="archive-intro entity-archive-intro"><p class="eyebrow"><?php echo esc_html($label); ?></p><h1>Khám phá <?php echo esc_html(strtolower($label)); ?></h1><p class="archive-summary">Duyệt các hồ sơ đang hoạt động; ảnh đại diện được lấy từ MediaUsage khi có.</p><form class="entity-filter" method="get" action="<?php echo esc_url(home_url($archivePaths[$type] ?? '/')); ?>"><label class="screen-reader-text" for="nhk-entity-q">Tìm trong <?php echo esc_attr(strtolower($label)); ?></label><input id="nhk-entity-q" name="nhk_entity_q" type="search" value="<?php echo esc_attr((string) ($archive['query'] ?? '')); ?>" placeholder="Tìm <?php echo esc_attr(strtolower($label)); ?>..."><button type="submit">Tìm</button></form></header>
  <?php if (($archive['available'] ?? true) === false): ?><div class="empty"><h2>Dữ liệu chưa sẵn sàng</h2><p>Kho hồ sơ hiện không thể truy vấn.</p></div><?php elseif (!empty($archive['items'])): ?><div class="entity-grid visual-entity-grid"><?php foreach ($archive['items'] as $item): $url = nhk_v3_public_url($item['url'] ?? null); if ($url === '') continue; $image = trim((string) ($item['media']['representative']['url'] ?? '')) ?: $fallback; ?><article class="entity-card visual-entity-card"><a class="entity-card-image" href="<?php echo esc_url($url); ?>"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr((string) ($item['media']['representative']['alt'] ?? '')); ?>" loading="lazy"></a><div class="entity-card-body"><p class="eyebrow"><?php echo esc_html($label); ?></p><h2><a href="<?php echo esc_url($url); ?>"><?php echo esc_html(nhk_v3_public_brand_text((string) ($item['name'] ?? ''))); ?></a></h2></div></article><?php endforeach; ?></div><?php else: ?><div class="empty"><h2>Chưa có hồ sơ phù hợp</h2><p>Thử từ khóa khác hoặc quay lại sau khi hồ sơ được bổ sung.</p></div><?php endif; ?>
  <?php $pages = (int) ceil((int) ($archive['total'] ?? 0) / max(1, (int) ($archive['per_page'] ?? 1))); if ($pages > 1): ?><nav class="entity-pagination" aria-label="Phân trang <?php echo esc_attr($label); ?>"><?php for ($i = 1; $i <= $pages; $i++): $url = rtrim((string) home_url($archivePaths[$type] ?? '/'), '/') . ($i > 1 ? '/page/' . $i . '/' : '/'); if (($archive['query'] ?? '') !== '') $url = add_query_arg('nhk_entity_q', $archive['query'], $url); $current = $i === (int) ($archive['page'] ?? 1); ?><a class="<?php echo $current ? 'current' : ''; ?>"<?php echo $current ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $i); ?></a><?php endfor; ?></nav><?php endif; ?>
<?php else: ?><div class="empty"><h1>Không thể tải hồ sơ</h1><p>Trang này hiện chưa sẵn sàng.</p></div><?php endif; ?>
</main>
<?php get_footer(); ?>
