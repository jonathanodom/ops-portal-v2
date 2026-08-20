# Closeout Review Photo Gallery

## Baseline and scope

This focused UI update branches from `main` at `3d8d20b27150126fd027daf2d234a9863e71aac6`. Previously, Office Closeout Review exposed active Visit evidence only as text links that navigated away from the review. The updated evidence section presents every active (`state=stored`) VisitMedia record across the Visit's Closeout versions as a responsive gallery.

The gallery includes only Closeout evidence. Removed Visit media, Service Ticket Files, Project Attachments, invoice documents, and unrelated media are excluded. Ordering is deterministic by Closeout version, media creation time, and media ID. No schema change or new query-per-photo behavior is introduced.

## Preview and interaction behavior

- JPEG, PNG, and WebP use compact lazy-loaded thumbnails and one shared full-size native `<dialog>` image.
- HEIC and HEIF remain visible as deliberate fallback tiles with an authenticated **Open original** action because browser preview support is inconsistent.
- A failed thumbnail or full-size image changes to a visible **Preview unavailable** state without retry loops or blocking review actions.
- Previous and Next wrap through browser-previewable photos. Arrow Left and Arrow Right provide the same navigation while the dialog is active.
- Escape and the 44px Close control close the dialog. Focus returns to the thumbnail that opened it.
- The dialog is full viewport on phones and approximately 96vw by 92vh on larger screens. Metadata remains visible without hover.

## Privacy and authorization

Both thumbnails and full images use the existing authenticated `field.media.show` route. The response remains organization-scoped, capability-checked, private, and `no-store`. The gallery never exposes the configured storage disk/key, creates a public URL, or grants evidence access to Billing or another unauthorized membership.

No generated thumbnails, image conversion, EXIF processing, public copies, CDN integration, bulk download, editing, re-categorization, or media deletion is part of this update.

## Validation

Coverage verifies the empty state, active/removed filtering, multiple Closeout versions, captions, categories, deterministic ordering, protected URLs, HEIC/HEIF fallback, private-media authorization, responsive widths, dialog navigation, keyboard close/navigation, focus restoration, review controls after closing, and accessibility.

Local validation before publication:

- Focused Closeout Review suite: 18 tests, 155 assertions.
- Complete PHPUnit suite with the CI-equivalent destructive-purge flag disabled: 377 tests, 3,091 assertions.
- Composer validation and security audit, Pint, compiled Blade lint, Vite production build, and `git diff --check`: passed.
- Isolated beta fixture validation: passed at the exact expected 250 customers, 400 locations, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 VisitMedia records.
- Review-detail benchmark: warm p95 15.2 ms with a maximum of 25 queries.
- Focused Playwright/axe gallery test: passed across 390, 768, 1280, 1440, and 1920 pixel widths.
