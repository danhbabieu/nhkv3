<?php
$view = is_array($args['view'] ?? null) ? $args['view'] : [];
$identity = is_array($view['identity'] ?? null) ? $view['identity'] : [];
$label = trim((string) ($args['label'] ?? 'Hồ sơ'));
$fallback = (string) ($args['fallback'] ?? get_theme_file_uri('/assets/default-archive.svg'));
$name = trim((string) ($view['title'] ?? $identity['name'] ?? $args['name'] ?? ''));
$summary = trim((string) ($view['summary'] ?? $view['description'] ?? ''));
$media = is_array($view['hero_media'] ?? null) ? $view['hero_media'] : [];
$image = trim((string) ($media['url'] ?? '')) ?: $fallback;
$alt = trim((string) ($media['alt'] ?? ''));
$counts = is_array($view['counts'] ?? null) ? $view['counts'] : [];
?>
<header class="entity-dossier-hero">
  <figure class="entity-hero-visual"><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($alt); ?>" loading="eager" fetchpriority="high"></figure>
  <div class="entity-hero-copy">
    <p class="eyebrow"><?php echo esc_html($label); ?></p>
    <h1><?php echo esc_html(nhk_v3_public_brand_text($name)); ?></h1>
    <?php if ($summary !== ''): ?><p class="entity-lead"><?php echo esc_html(nhk_v3_public_copy($summary)); ?></p><?php endif; ?>
    <?php $statMap = ['media_count' => 'hình ảnh', 'knowledge_claim_count' => 'ghi nhận tri thức', 'video_count' => 'video']; $stats = []; foreach ($statMap as $key => $statLabel) { $value = (int) ($counts[$key] ?? 0); if ($value > 0) $stats[] = [$value, $statLabel]; } if ($stats !== []): ?><div class="dossier-stats"><?php foreach ($stats as $stat): ?><span><strong><?php echo esc_html((string) $stat[0]); ?></strong> <?php echo esc_html($stat[1]); ?></span><?php endforeach; ?></div><?php endif; ?>
  </div>
</header>
