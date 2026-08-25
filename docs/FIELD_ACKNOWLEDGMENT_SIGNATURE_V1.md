# Field Acknowledgment & Signature V1

## Scope

Field closeouts now support either an on-site point-of-contact signature or a categorized acknowledgment fallback. Signature evidence belongs to one immutable Closeout version; correction versions require a new acknowledgment and retain prior evidence only in the authorized version timeline. Document profiles and generated operational documents remain future work.

## Data and storage

- `closeouts.representative_role` stores an optional POC role/title.
- `closeout_acknowledgment_signatures` snapshots signer identity, statement version/text, capture actor/time, MIME, size, and SHA-256.
- PNG objects use opaque UUID keys on `FIELD_ACK_SIGNATURE_DISK` (falling back to `FIELD_MEDIA_DISK`).
- Objects are private and served only through organization-scoped, capability-aware controllers with `no-store` response headers.
- Signature objects are included in field-test Ticket purge and local-example reset storage manifests.

## Submission matrix

| Path | Requirements |
| --- | --- |
| Signed on-site | POC/customer name, optional role, confirmation, nonblank signature PNG |
| Acknowledgment fallback | Allowed category and required detail; no signature accepted |
| Administrative manual closeout | Existing office acknowledgment behavior remains unchanged |
| Returned correction | Prior signature is read-only version history; current version requires a new acknowledgment |

The frozen statement is configured as `field_execution.ack_statement` with version `service-closeout-v1`. Signature payload validation is bounded before database work, rejects malformed/blank images, and deletes a newly stored object if the surrounding submission transaction rolls back.

## Privacy and auditing

Audit metadata includes record IDs, statement version, and boolean presence indicators only. It excludes the signer name, role value, signature bytes/storage key, closeout narrative, and fallback detail. Office inspection requires `closeouts.inspect`; field access continues to use the Visit policy.

## Rollback

Rollback drops the signature table before removing `closeouts.representative_role`. Stored objects referenced by retained signature rows must be exported or deleted through the guarded purge/reset workflow before a production rollback.

## Validation

- Retained SQLite backup/restore verification: passed; SHA-256 `8bc8a4b758f8160061ec4cdd3f81287356ee60670cf6dbe85540f4c28def1516`.
- Additive migration on retained data: passed. Isolated fresh migrate, rollback, and reapply: passed.
- PHPUnit: 411 passed, 3,368 assertions (CI-safe destructive-purge default).
- Focused acknowledgment/review/purge/UI regression: 48 passed, 441 assertions before final signed-review assertions; final signature test passed with 20 assertions.
- Beta setup/fixture validation: passed, including 250 Customers, 500 Service Tickets, 1,000 Visits, 200 Closeouts, and 500 media records.
- Beta benchmark: dashboard 19.9 ms p95; Today 11.3 ms; Dispatch 20.5 ms; Project detail 22.7 ms; Ticket detail 20.5 ms; Review detail 12.5 ms; media first byte 0.1 ms.
- Pint, compiled Blade, Vite production build, Composer validation/audit, and diff checks: passed.
- Playwright discovered 50 desktop/mobile cases locally; they were credential-gated and skipped. The updated 390×844 signature-pad/axe path runs in the GitHub browser job.
