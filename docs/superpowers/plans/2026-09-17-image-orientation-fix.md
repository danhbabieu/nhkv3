# NHK V3 Image Orientation Fix Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Normalize EXIF orientation exactly once before measuring, resizing and generating the canonical public WebP.

**Architecture:** Keep the existing WordPress media ingestor and attachment/Media boundaries unchanged. Move orientation responsibility to a small WordPress image-editor adapter that invokes the editor's `maybe_exif_rotate()` method; the ingestor then measures the already-normalized editor and continues its existing proportional WebP path.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress `WP_Image_Editor_GD`, GD/EXIF, WebP.

**Spec:** User-provided request `FIX ONLY — NHK V3 IMAGE ORIENTATION BUG`.

## Global Constraints

- Normalize EXIF before effective dimensions and resize are calculated.
- Public WebP uses a 1200px maximum long edge, preserves aspect ratio, never upscales/crops/stretches.
- Preserve the private source-original and one canonical Media identity.
- Do not repair old attachments, mutate staging/live data, deploy or push.

---

### Task 1: Add pixel-level regression coverage

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/WordPressImageOrientationTest.php`

- [x] **Step 1: Write tests for portrait/landscape Orientation=1, EXIF 6, EXIF 8, no EXIF, and no-upscale output.**
- [x] **Step 2: Run the focused test and confirm the missing normalization behavior fails before implementation.**

### Task 2: Apply the minimal shared-pipeline fix

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressImageOrientationNormalizer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Media/WordPressMediaAttachmentIngestor.php`

- [x] **Step 1: Invoke `WP_Image_Editor::maybe_exif_rotate()` after editor load and before `get_size()`.**
- [x] **Step 2: Keep the existing resize/save/attachment flow unchanged.**
- [x] **Step 3: Re-run pixel tests and confirm WebP orientation and dimensions.**

### Task 3: Verify the complete boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Integration/WordPressMediaIngestIntegrationTest.php`

- [x] **Step 1: Add EXIF 6/8 integration cases that read back public binary pixels and WordPress attachment dimensions.**
- [x] **Step 2: Run focused unit and integration coverage, then the broadest practical suite.**
- [x] **Step 3: Run PHP lint, diff check and secret review; report unrelated environment failures without changing them.**
