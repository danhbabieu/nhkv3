# MCP Documentation Bootstrap Design

## Goal

Cho phép MCP client mới đọc luật canonical và biết capability runtime hiện tại
mà không cần GitHub connector, qua hai read-only tools: `nhk.docs.bootstrap`
và `nhk.docs.get`.

## Design

`McpDocumentationRegistry` là boundary duy nhất cho document key, allowlist,
source resolution, bounded UTF-8 reads và snapshot identity. Registry không
nhận path từ client. Trong checkout nó ưu tiên repo-level `docs/`; khi artifact
được đóng gói không có repo docs, nó dùng generated `nhk-core/resources/docs`
hoặc root được cấu hình bởi deployment. Các file được đọc vẫn phải khớp
allowlist tương đối.

`McpTransport` thêm hai read tools vào cùng catalog/dispatch path hiện hữu và
kiểm tra capability `read`. `nhk.docs.bootstrap` chỉ trả metadata nhỏ: source
revision/hash, Constitution identity, required reading, canonical contract
keys, runtime manifest lấy từ `McpCapabilityManifest`, registry gaps và cờ phân
biệt `canonical_contract`/`runtime_status`. `nhk.docs.get` trả một document đã
resolve cùng metadata/hash; không expose arbitrary source code, config, secrets
hay filesystem paths.

Runtime status chỉ phản ánh catalog đăng ký và trạng thái quan sát được của
surface MCP; documentation content không được dùng để suy ra LIVE.

## Security and limits

- Keys là enum từ registry; path traversal và absolute path không bao giờ được
  resolve.
- Chỉ file `AGENTS.md` và các tài liệu canonical được allowlist; không đọc
  `.env`, `wp-config.php`, source code hoặc file ngoài roots.
- Mỗi document bị giới hạn 512 KiB và bootstrap bị giới hạn riêng; bytes phải
  là UTF-8 hợp lệ.
- File missing/unreadable/invalid UTF-8 trả lỗi reader-safe, không trả path
  hoặc fallback arbitrary.

## Verification

Unit tests cover key resolution, traversal rejection, UTF-8/size limits,
snapshot hash, bootstrap shape and catalog dispatch. MCP integration coverage
asserts the tools appear in `tools/list`; live target discovery remains a
separate environment gate and is not claimed by code presence.
