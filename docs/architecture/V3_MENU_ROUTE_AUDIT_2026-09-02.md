# V3 Public Menu Route Audit — 2026-09-02

## Runtime status

The active stored WordPress menu could not be read: `wp --path=public menu
list --format=json` fails with `Error establishing a database connection`.
Consequently, stored menu item IDs and current URLs are **UNVERIFIED**, and no
stored menu mutation was attempted.

## Before/after matrix

| Label | Stored current URL / source | Required canonical URL | Code fallback source |
|---|---|---|---|
| Tri thức | UNVERIFIED — WordPress stored menu unavailable | `/tri-thuc/` | `themes/nhk-v3/functions.php` |
| Thương hiệu | UNVERIFIED — WordPress stored menu unavailable | `/thuong-hieu/` | `themes/nhk-v3/functions.php` |
| Mẫu | UNVERIFIED — WordPress stored menu unavailable | `/mau/` | `themes/nhk-v3/functions.php` |
| Bộ máy | UNVERIFIED — WordPress stored menu unavailable | `/bo-may/` | `themes/nhk-v3/functions.php` |
| Bản nhạc | UNVERIFIED — WordPress stored menu unavailable | `/ban-nhac/` | `themes/nhk-v3/functions.php` |
| So sánh | UNVERIFIED — WordPress stored menu unavailable | `/so-sanh/` | `themes/nhk-v3/functions.php` |
| Linh kiện | UNVERIFIED — WordPress stored menu unavailable | `/linh-kien/` | `themes/nhk-v3/functions.php` |
| Hiện vật | UNVERIFIED — WordPress stored menu unavailable | `/hien-vat/` | `themes/nhk-v3/functions.php` |
| Video | UNVERIFIED — WordPress stored menu unavailable | `/video/` | `themes/nhk-v3/functions.php` |
| Góc chia sẻ | Retained only by existing fallback/editorial section contract | `/goc-chia-se/` | `themes/nhk-v3/functions.php` |

Classification and Product are intentionally absent from this primary menu.
Once WordPress is available, record stored item IDs and URLs first, then make
only targeted updates for stale rows and rerun rendered-navigation HTTP checks.
