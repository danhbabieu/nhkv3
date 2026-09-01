# 15. Frontend Design & UX Law

The public NHK experience is Vietnamese-first, editorial and readable. Internal
class names, registry keys, UUIDs and governance terminology are never required
to appear in visitor-facing copy.

- Normal H1 is 40–48px on desktop and 30–36px on mobile; H2 is 24–32px.
- Body text is 16–18px with approximately 1.6–1.75 line height. Normal pages
  must not use oversized SaaS-style display typography or blanket 800/900 weight.
- Shared tokens define typography, spacing, container widths, responsive gutters,
  radii, colors and shadows. Components own their spacing; templates do not
  stack repeated compensating margins or page-specific overrides.
- Reading content is approximately 720–800px wide. Discovery layouts use a
  controlled wide container, two-column maximum at desktop and one column on
  small screens. Section gaps are approximately 48–72px desktop and 32–48px mobile.
- Cards and grids use consistent gaps, stable image aspect ratios, bounded text
  and one clear action. Empty modules are hidden; a single honest empty state is
  used when a page itself has no public data.
- Every public technical label is localized naturally into Vietnamese without
  renaming internal PHP, registry or database concepts.
- Semantic HTML, heading order, keyboard focus, touch-sized controls, contrast,
  meaningful alt text, lazy below-fold media and no horizontal overflow are mandatory.
- Canonical public links never expose UUIDs, stable keys or internal namespaces.
