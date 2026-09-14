<?php
$eyebrow = trim((string) ($args['eyebrow'] ?? ''));
$title = trim((string) ($args['title'] ?? ''));
$url = function_exists('nhk_v3_public_url') ? nhk_v3_public_url($args['url'] ?? null) : '';
$cta = trim((string) ($args['cta'] ?? ''));
if ($title === '') return;
?>
<div class="section-head">
  <div><?php if ($eyebrow !== ''): ?><p class="eyebrow"><?php echo esc_html($eyebrow); ?></p><?php endif; ?><h2><?php echo esc_html($title); ?></h2></div>
  <?php if ($url !== '' && $cta !== ''): ?><a class="text-link" href="<?php echo esc_url($url); ?>"><?php echo esc_html($cta); ?></a><?php endif; ?>
</div>
