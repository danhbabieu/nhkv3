# Video model

> **NON-NORMATIVE.** Đây là evidence mô hình lịch sử. Nếu mâu thuẫn với
> `docs/constitution/NHK_V3_CONSTITUTION.md`, Hiến pháp kiểm soát.

Video là external reference. Luồng chuẩn: YouTube URL → normalize → external
video ID → canonical Video → metadata → semantic relations. Mặc định không tải
file video về server; graph xử lý quan hệ số lượng lớn thay vì nhồi ID vào post.

## Current canonical Video frontend law — 2026-09-07

Video is a canonical V3 object with Public Identity. Every public-eligible
Video resolves to the first-party page `/video/{slug}/`. The external platform
URL/ID remains source identity, provenance, embed provider and reconciliation
reference; it is never the frontend canonical destination. Admin separates
“Xem trên web” (first-party page) from “Mở nguồn gốc” (external source).

The page query reads canonical Video projection/read-model data, public Graph
relations, public-safe knowledge and reader-safe provenance. It must support the
persisted `metadata.source` shape and approved compatibility shapes by
normalizing in the application/query layer, without duplicating semantic data.
