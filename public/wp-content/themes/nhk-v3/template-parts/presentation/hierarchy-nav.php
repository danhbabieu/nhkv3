<?php
$hierarchy = is_array($args['hierarchy'] ?? null) ? $args['hierarchy'] : [];
$parent = is_array($hierarchy['parent'] ?? null) ? $hierarchy['parent'] : [];
$children = is_array($hierarchy['children'] ?? null) ? $hierarchy['children'] : [];
$siblings = is_array($hierarchy['siblings'] ?? null) ? $hierarchy['siblings'] : [];
if ($parent === [] && $children === [] && $siblings === []) return;
$renderLink = static function (array $item): void { $name = trim((string) ($item['name'] ?? '')); if ($name === '') return; $url = nhk_v3_public_url($item['url'] ?? null); if ($url !== '') echo '<a href="' . esc_url($url) . '">'; echo esc_html($name); if ($url !== '') echo '</a>'; };
$render = static function (array $item) use ($renderLink): void { echo '<li>'; $renderLink($item); echo '</li>'; };
?>
<nav class="hierarchy-nav" aria-label="Cấu trúc liên quan">
  <?php if ($parent !== []): ?><p class="hierarchy-parent">Thuộc nhóm: <strong><?php $renderLink($parent); ?></strong></p><?php endif; ?>
  <?php if ($children !== []): ?><details open><summary>Nhóm con</summary><ul><?php foreach ($children as $child) if (is_array($child)) $render($child); ?></ul></details><?php endif; ?>
  <?php if ($siblings !== []): ?><details><summary>Nhóm liên quan</summary><ul><?php foreach ($siblings as $sibling) if (is_array($sibling)) $render($sibling); ?></ul></details><?php endif; ?>
</nav>
