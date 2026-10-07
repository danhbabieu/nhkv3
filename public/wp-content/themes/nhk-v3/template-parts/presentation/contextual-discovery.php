<?php
$modules = is_array($args['modules'] ?? null) ? $args['modules'] : [];
$context = (string) ($args['context'] ?? 'entity');
if ($modules === []) return;
?>
<section class="contextual-content-discovery" aria-label="Nội dung liên quan đã có">
  <?php foreach ($modules as $module):
    $kind = (string) ($module['kind'] ?? 'entity');
    $items = is_array($module['items'] ?? null) ? $module['items'] : [];
    if ($items === []) continue;
  ?>
    <section class="contextual-discovery-module discovery-kind-<?php echo esc_attr($kind); ?>">
      <?php get_template_part('template-parts/presentation/section-header', null, ['eyebrow' => !empty($module['content_backed']) ? 'Liên quan' : nhk_v3_contextual_discovery_copy($context), 'title' => (string) ($module['title'] ?? 'Liên quan')]); ?>
      <div class="compact-discovery-list">
        <?php foreach ($items as $item):
          $title = nhk_v3_public_brand_text(trim((string) ($item['title'] ?? $item['name'] ?? '')));
          $url = nhk_v3_public_url($item['url'] ?? null);
          if ($kind === 'media') {
              $visual = nhk_v3_media_presentation($item);
              $url = nhk_v3_media_content_url($item);
          }
          if ($title === '' || $url === '') continue;
        ?>
          <article class="compact-discovery-item">
            <?php if ($kind === 'media' && $visual['url'] !== ''): ?><a class="compact-discovery-media-link" href="<?php echo esc_url($url); ?>"><span class="compact-discovery-thumb"><img src="<?php echo esc_url($visual['url']); ?>" alt="<?php echo esc_attr($title); ?>" loading="lazy" decoding="async"<?php if ($visual['srcset'] !== ''): ?> srcset="<?php echo esc_attr($visual['srcset']); ?>"<?php endif; ?><?php if ($visual['sizes'] !== ''): ?> sizes="<?php echo esc_attr($visual['sizes']); ?>"<?php endif; ?><?php if ($visual['width'] > 0): ?> width="<?php echo esc_attr((string) $visual['width']); ?>"<?php endif; ?><?php if ($visual['height'] > 0): ?> height="<?php echo esc_attr((string) $visual['height']); ?>"<?php endif; ?>></span><strong><?php echo esc_html($title); ?></strong></a>
            <?php elseif ($kind === 'video'): ?><?php get_template_part('template-parts/presentation/video-card', null, ['item' => $item, 'compact' => true]); ?>
            <?php endif; ?>
            <?php if ($kind !== 'video' && $kind !== 'media'): ?><a class="compact-discovery-link" href="<?php echo esc_url($url); ?>"><strong><?php echo esc_html($title); ?></strong></a><?php endif; ?>
          </article>
        <?php endforeach; ?>
      </div>
      <?php $cta = is_array($module['cta'] ?? null) ? $module['cta'] : []; $ctaUrl = nhk_v3_public_url($cta['path'] ?? null); if ($ctaUrl !== ''): ?><a class="contextual-module-cta" href="<?php echo esc_url($ctaUrl); ?>"><?php echo esc_html((string) ($module['cta_label'] ?? 'Xem thêm')); ?> <span aria-hidden="true">→</span></a><?php endif; ?>
    </section>
  <?php endforeach; ?>
</section>
