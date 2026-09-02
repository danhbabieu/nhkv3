# V3 Public Menu Route Audit — 2026-09-02

## Runtime status

The local WordPress runtime is available. The active theme is `nhk-v3`, but
there is no stored menu to inspect or mutate: `wp --path=public menu list
--format=json` returned `[]`, and `theme_mods_nhk-v3.nav_menu_locations` is an
empty array. The rendered primary navigation therefore uses the theme
fallback `nhk_v3_nav_fallback()`; no targeted stored-menu correction was
needed.

## Before/after matrix

| Label | Stored current URL / source | Required canonical URL | Code fallback source |
|---|---|---|---|
| Tri thức | NONE — no stored menu rows; fallback rendered | `/tri-thuc/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| Thương hiệu | NONE — no stored menu rows; fallback rendered | `/thuong-hieu/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| Mẫu | NONE — no stored menu rows; fallback rendered | `/mau/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| Bộ máy | NONE — no stored menu rows; fallback rendered | `/bo-may/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| Bản nhạc | NONE — no stored menu rows; fallback rendered | `/ban-nhac/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| So sánh | NONE — no stored menu rows; fallback rendered | `/so-sanh/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| Linh kiện | NONE — no stored menu rows; fallback rendered | `/linh-kien/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| Hiện vật | NONE — no stored menu rows; fallback rendered | `/hien-vat/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| Video | NONE — no stored menu rows; fallback rendered | `/video/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |
| Góc chia sẻ | NONE — no stored menu rows; fallback/editorial section rendered | `/goc-chia-se/` | `themes/nhk-v3/functions.php::nhk_v3_nav_fallback()` |

Classification and Product are intentionally absent from this primary menu.
Local rendered navigation and the read-only staging navigation both expose the
Vietnamese canonical routes above; no legacy technical navigation links were
observed. The legacy roots remain one-hop redirects for compatibility.
