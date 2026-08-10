# Phase 5 Deferred Decision Log

| Deferred request | Why it is deferred | Revisit trigger |
| --- | --- | --- |
| Production deployment and DNS cutover | Phase 5 is a local/CI validation gate, not a production-readiness claim. | Manual beta sign-off, approved production architecture, security review, recovery drill, and explicit owner approval. |
| Accounting integration | Handoffs are intentionally provider-neutral and auditable. | Billing workflow is accepted and an accounting system/contract is selected. |
| Payments and receipts | Phase 6 implements invoices while payment credentials and settlement remain outside the portal. | Phase 6 owner gate passes and a provider, PCI boundary, methods, refund authority, and reconciliation ownership are approved for Phase 7. |
| Inventory mutation | Proposals are evidence, not stock movements. | Parts catalog, warehouse ownership, valuation, and adjustment controls are defined. |
| Notifications | Delivery, consent, escalation, and retry policy are not yet defined. | Operational owners approve channels, templates, quiet hours, and failure handling. |
| Offline synchronization | Phase 5 only protects in-page unsaved content and explicit retry. | Field connectivity evidence shows the need and conflict/security semantics are designed. |
| External calendars and maps | They add credentials, privacy, quotas, and reconciliation. | Dispatch validates the core queue and selects providers/data-sharing terms. |
| CRM import or synchronization | Beta/production data copying remains prohibited. | Source ownership, mapping, deduplication, consent, and reversible migration are approved. |
| Malware scanning | Local beta accepts synthetic images only; production upload controls are incomplete without it. | Production object storage/vendor is selected before external uploads are enabled. |
| Production object storage | Local private storage is sufficient for beta. | Retention, encryption, backup, lifecycle, regional, and signed-delivery requirements are approved. |
| Completed-ticket reopening | Phase 4 intentionally makes completion terminal. | A correction/accounting policy defines versioning, handoff reversal, audit, and permissions. |

These items are exclusions, not implicit authorization. Each requires a separately reviewed phase or decision record.
