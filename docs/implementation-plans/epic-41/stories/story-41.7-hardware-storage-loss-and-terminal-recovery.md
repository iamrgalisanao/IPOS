# Story 41.7 Hardware, Storage-Loss, and Terminal Recovery

## Status

Implemented - Local Verification Complete

Date: 2026-07-18

## Epic

Epic 41 POS Terminal Offline Readiness and Release Validation

## Objective

Validate terminal recovery, printer/drawer boundaries, hardware availability, browser storage-loss behavior, service-worker upgrade safety, and support procedures so that:

1. terminal identity loss fails closed,
2. storage-loss and corruption cannot create false accepted-sale evidence,
3. orphaned local queue records require support review rather than silent reuse,
4. hardware unavailable and hardware failure are documented separately,
5. no hardware readiness claim is made without physical device validation,
6. support can extract masked diagnostics and recover without rewriting original cashier attribution.

Story 41.7 is not a wholesale hardware rewrite and not a new sync acceptance engine. It is the recovery and physical-boundary validation story that proves Stories 41.1 through 41.6 remain honest under terminal reinstall, browser-data loss, storage failure, service-worker upgrade, and hardware-deferred pilot conditions.

## Dependencies

Requires:

1. Story 41.1 offline architecture and policy lock.
2. Story 41.2 durable offline transaction queue, capture-uncertain recovery, tombstones, diagnostics, and support export contracts.
3. Relevant portions of Story 41.3:
   - server synchronization identity,
   - idempotency and accepted/replayed semantics,
   - terminal-bound sync authorization.
4. Existing hardware adapters:
   - `PosHardwareAdapter`,
   - `BrowserPrintAdapter`,
   - `NoOpHardwareAdapter`.
5. Existing browser storage and shell behavior:
   - IndexedDB offline queue,
   - service worker shell cache,
   - terminal identity binding middleware and activation flow.
6. Stories 41.4 through 41.6 for review, policy, and consequence contracts that recovery must not weaken.

## Complexity

Large

## Benchmark Direction

Primary operational benchmark:

```text
StoreHub-style operator expectation that offline sales remain diagnosable
and recoverable without inventing a second authority ledger
```

Secondary support benchmark:

```text
Mosaic-style support visibility for abnormal terminal, date, and recovery states
```

Secondary cashier-simplicity benchmark:

```text
UTAK-style plain cashier messaging: simple status, no technical dump
```

Recommended IPOS implementation style:

```text
IPOS-owned terminal epoch + queue tombstone recovery
+
existing OfflineSalesQueueService durability and diagnostics
+
adapter-based hardware boundary without readiness overclaim
+
support-masked extraction playbook
+
evidence-based hardware deferral for pilot
```

Provider benchmarks are operational references only. Do not add a runtime dependency on StoreHub, Mosaic, or UTAK.

## Architecture Constraints

Story 41.7 must preserve these locked decisions:

1. Terminal identity is mandatory for shell access and synchronization.
2. Terminal reinstall or storage loss cannot silently reuse old local queue identity.
3. Terminal rebinding must increment `terminal_binding_epoch` and never restart a prior epoch silently.
4. Old queue entries remain bound to their original terminal epoch.
5. Orphaned local queued transactions require support review.
6. Offline capture is locally confirmed only after write, read-back, and checksum verification.
7. If durable local persistence fails, the cashier must not be shown a successful offline sale state.
8. Local browser storage is provisional evidence only and is never the official sale ledger.
9. Accepted records retain a minimal local tombstone before privacy-based compaction or purge.
10. Checksums detect accidental corruption; they are not cryptographic authenticity signatures unless a device-bound key design is explicitly approved.
11. Service-worker and foreground sync must not race; only one queue processor may actively sync a terminal queue at a time.
12. `clear cache and reload` is not the default support procedure when unsynced records exist.
13. Hardware readiness cannot be claimed without physical device evidence.
14. Hardware unavailable is distinct from hardware available-but-failed.
15. Cashier switching never changes the actor attributed to existing envelopes.
16. Support diagnostics must not leak credentials, PINs, card data, or secrets.
17. No story may introduce browser-local official sale, receipt, inventory, loyalty, store-credit, or fiscal ledgers.
18. Story 41.7 must not invent new top-level sync acceptance rules or rewrite Stories 41.3–41.6 consequence contracts.

## Specification Refinements Locked for Implementation

The following refinements close the provider-review findings and are part of the implementation contract.

### Browser Storage Terminology

Implementation and support copy must distinguish:

1. shell cache,
2. HTTP browser cache,
3. service-worker cache,
4. IndexedDB,
5. `localStorage`,
6. cookies/session data,
7. all site data.

Rules:

1. Shell-cache refresh may be safe only when it does not delete IndexedDB or queue evidence.
2. Clearing all site data is destructive.
3. Support instructions must name the exact action being requested.
4. UI must never use generic "clear cache" guidance when unresolved sales may exist.

### Storage Disappearance Detection

Complete browser site-data loss cannot be proven from the new empty profile alone.

First-release detection is evidence-qualified:

1. When online, the terminal may report non-sensitive queue-health metadata:
   - terminal ID,
   - binding epoch,
   - highest local sequence,
   - unresolved count,
   - accepted tombstone count,
   - queue schema version,
   - storage capability.
2. Heartbeats must not upload raw unsynced sale payloads.
3. On reactivation, prior server-known queue metadata may be compared with the new local profile.
4. If prior external evidence is unavailable, the result is `possible_storage_loss`, not confirmed loss.

### Binding Epoch Source of Truth

`terminal_binding_epoch` is server-issued and monotonic. The browser may cache the epoch but cannot increment or restore it authoritatively.

Activation or rebind must return:

```text
terminal_id
terminal_binding_epoch
binding_issued_at
binding_status
```

A client-proposed older epoch must be rejected.

### Rebind Versus Routine Login

Epoch increments are allowed only for:

1. terminal activation,
2. terminal reinstall or recovery activation,
3. administrative rebind,
4. verified profile replacement.

Epoch increments are not allowed for:

1. cashier login,
2. cashier logout,
3. screen lock,
4. browser reload,
5. service-worker update,
6. ordinary token refresh.

### Orphan Classification

A local envelope is orphaned when its terminal identity or binding epoch cannot be validated as the currently authorized owner, and the system cannot safely prove a valid ordinary sync path.

Reason codes:

```text
orphan_terminal_missing
orphan_terminal_mismatch
orphan_epoch_mismatch
orphan_binding_revoked
orphan_identity_unverifiable
```

Ordinary pending records are not orphaned.

### Support Review Boundary

Support may:

1. inspect,
2. export,
3. compare with server imports,
4. confirm accepted or tombstone state,
5. classify unresolved cash,
6. recommend manual reconciliation.

Support may not:

1. rewrite `terminal_id`,
2. rewrite `terminal_binding_epoch`,
3. rewrite original cashier attribution,
4. submit an old payload through a new terminal as if originally captured there,
5. mint an accepted sale outside the normal sync flow.

Any future support-assisted recovery mutation requires a separate architecture story.

### Checksum Canonicalization

Envelope checksums must:

1. hash immutable business payload only,
2. exclude mutable lease, retry, and status fields,
3. use deterministic key order,
4. normalize numbers and timestamps,
5. store checksum algorithm and payload-schema version.

Required fields:

```text
payload_checksum
payload_checksum_algorithm
payload_canonicalization_version
```

### Tombstone Integrity and Compaction

Tombstones must have independent integrity fields:

```text
tombstone_checksum
tombstone_checksum_algorithm
tombstone_schema_version
```

Tombstone checksums are integrity evidence only, not device-authenticity proof.

Payload compaction is allowed only when:

1. server status is accepted, replayed, or finally resolved,
2. required server identity is present,
3. retention window has elapsed,
4. no cash dispute is open,
5. no support hold exists,
6. receipt or fiscal references required by policy are retained,
7. tombstone write and read-back verification succeeds before payload deletion.

### Automatic Sync After Cashier Change

Sync executes under terminal authorization while the envelope retains original cashier attribution. The current signed-in cashier is not substituted into server sale actor fields.

Support actions may separately record `support_operator_id`.

### Service-Worker Compatibility and Recovery Scans

Service-worker compatibility is defined by:

```text
shell_version
queue_schema_min
queue_schema_max
sync_contract_version
```

A worker may activate only when it:

1. supports the current queue schema,
2. can safely invoke the approved migrator, or
3. enters shell-only support-required mode without taking queue ownership.

Startup recovery scans must respect queue leases:

1. read-only scans may run without queue ownership,
2. state promotion such as `capture_uncertain` to `durably_captured` requires a queue maintenance lease,
3. export may read a consistent snapshot but must not extend or steal the sync lease,
4. corruption classification must use compare-and-set semantics so newer server state is not overwritten.

### Queue Schema Migration

Queue migration must be versioned, non-destructive, and lease-aware:

1. copy unresolved records,
2. canonicalize and transform,
3. write the new versioned representation,
4. read back and verify checksum,
5. mark migration complete only after verification,
6. preserve the original evidence if migration fails,
7. prevent the service worker from performing destructive migration independently.

### Hardware Evidence Ownership

Story 41.7 produces terminal hardware validation evidence. Story 41.8 consumes it as release-gate input.

Evidence must be stored in either:

1. a dedicated database record, or
2. a versioned pilot evidence artifact with a stable identifier.

It must not exist only as free-text notes.

### BrowserPrintAdapter Status Semantics

Browser print support does not prove physical printer readiness.

Required status interpretation:

```text
capability = available_limited
physically_validated = false
status_source = browser_api_presence
```

The browser print dialog does not prove a printer is installed, online, printed successfully, formatted correctly, or connected to a cash drawer.

### Scanner Boundary

Scanner behavior is documentation-only pilot evidence for this story. Story 41.7 does not introduce scanner adapter implementation.

## Scope

In scope:

1. Terminal reinstall recovery specification and implementation guardrails.
2. Storage-loss recovery specification and implementation guardrails.
3. Service-worker upgrade, stale service-worker, and cache recovery rules.
4. IndexedDB corruption, quota, and storage-unavailable behavior.
5. Browser-data clearing behavior and cashier messaging.
6. Browser uncertain-write-result recovery.
7. Local record exists but UI did not show success recovery.
8. Local success displayed but storage disappears afterward recovery.
9. Queue tombstone exists but full payload is gone recovery.
10. Terminal-binding epoch recovery after reinstall/rebind.
11. Accepted tombstone recovery and identity continuity.
12. Queue extraction for support.
13. Support extraction with masked data.
14. Cashier changed after queue creation attribution preservation.
15. Receipt printer validation matrix.
16. Cash drawer validation matrix.
17. Hardware-deferred evidence rules for pilot.
18. Physical hardware UAT checklist where devices are available.
19. Support playbook for recovery and deferred-hardware operations.

Out of scope:

1. Claiming hardware readiness without physical devices.
2. Replacing hardware adapters wholesale or introducing Android bridge adapters beyond existing abstraction boundaries.
3. Fiscal receipt certification or official offline invoice issuance.
4. New sync acceptance, conflict classification, inventory, loyalty, or store-credit rules.
5. Full branch rollout decision (owned by Story 41.8).
6. Redesigning the official server sale ledger.
7. Browser-local encryption marketed as strong security without device-bound keys.
8. Automatic silent ownership transfer of another terminal's queued records.

## Current Implementation Context

Relevant existing code and docs:

1. `resources/js/POS/offline/offlineSalesQueue.ts`
   - Canonical offline sale envelope store.
   - Persistence states: `creating`, `persisting`, `durably_captured`, `capture_uncertain`, `storage_failed`.
   - Read-back verification via `verifyLocalCapture()`.
   - Tombstone store: `offline_tombstones`.
   - Diagnostics bundle: `getDiagnosticsBundle()`.
   - Prune path creates tombstones then deletes full payloads.
   - Fields include `terminal_id`, `terminal_binding_epoch`, hashes, lease, and resolution state.

2. `resources/js/POS/offline/offlineSyncManager.ts`
   - Foreground sync orchestration.
   - Must remain single-writer with lease semantics; service-worker races are forbidden.

3. `resources/js/POS/Hardware/PosHardwareAdapter.js`
   - Abstract contract: `printReceipt`, `openCashDrawer`, `getPrinterStatus`.

4. `resources/js/POS/Hardware/BrowserPrintAdapter.js`
   - Browser `window.print()` fallback.
   - Cannot open cash drawer natively.
   - Reports printer status optimistically as `online`.

5. `resources/js/POS/Hardware/NoOpHardwareAdapter.js`
   - Safe no-op default for missing hardware.
   - Reports printer status `offline`.

6. `public/sw.js` and `resources/views/app.blade.php`
   - POS terminal shell service worker and versioned cache key.
   - Upgrade/reload protection already present; Story 41.7 must ensure upgrades do not destroy unresolved queue evidence.

7. Terminal identity binding
   - Middleware and POS terminal route group require verified terminal context.
   - Missing or mismatched terminal context fails closed.

8. Story 41.2 contracts already define:
   - capture-uncertain recovery classifications,
   - support export safeguards,
   - retention/tombstone minimum fields,
   - queue health states,
   - service-worker compatibility rules.

9. Architecture Lock sections 16–21 and 19 define recovery, storage, hardware, and support observability.

## Architectural Gap Being Closed

Stories 41.2 through 41.6 established durable capture, sync, conflict, policy, and consequence behavior. Residual operational risk remains around device lifecycle and physical peripherals:

```text
terminal reinstall / browser data cleared
        |
        |-- old epoch queue may still exist on another profile or not exist at all
        |-- new activation must create a new binding epoch
        |-- orphaned records require support, not silent claim
        |
storage corruption / quota / uncertain write
        |
        |-- no false success UI
        |-- capture_uncertain or storage_failed preserved
        |-- support extraction without repair-by-delete
        |
service-worker upgrade / stale cache
        |
        |-- new worker must read existing queue
        |-- no destructive cache reset while unresolved records exist
        |
hardware unavailable vs hardware failed
        |
        |-- documented separately
        |-- deferred evidence rules for pilot
        |-- no readiness claim without physical validation
```

Story 41.7 closes the gap between “queue and sync work in the happy path” and “recovery, support, and hardware boundaries are honest under real terminal failure modes.”

## Recovery Scenario Contract

### R1 - Terminal reinstall recovery

When the terminal app is reinstalled or the browser profile is new:

1. shell access still requires verified terminal identity,
2. activation/rebinding must mint or advance `terminal_binding_epoch`,
3. the new epoch must not silently resume another epoch's sequence as if continuous,
4. any residual records discovered under an old epoch remain bound to that epoch,
5. cashier messaging must state that prior offline queue recovery requires support when identity continuity cannot be proven,
6. sync must not auto-claim foreign or orphaned queue ownership.

### R2 - Storage-loss recovery

When IndexedDB/local storage is cleared or unavailable:

1. missing local queue is not proof that no cash was collected,
2. if only server-side accepted evidence exists, recovery uses server sale identity and accepted tombstone/server import records,
3. if neither local nor server evidence exists, support procedure is manual cash reconciliation, not automated reconstruction,
4. UI must never invent an accepted local sale after storage loss,
5. offline capture is blocked while storage is unavailable.

### R3 - Service-worker upgrade, stale worker, and cache recovery

Rules:

1. new worker must not take control until it can read the existing queue schema or preserve unresolved records as support-required,
2. cache upgrade may replace shell assets but must not delete IndexedDB queue stores,
3. stale worker that cannot process current contract enters support-required or fails closed for new capture,
4. `clear site data` / `clear cache and reload` is forbidden as first-line support guidance when pending, failed, conflict, capture-uncertain, or review-required records may exist,
5. upgrade prompts must warn when unresolved offline records exist.

### R4 - IndexedDB corruption and quota behavior

States:

```text
storage_available
queue_capacity_warning
queue_capacity_block
storage_unavailable
storage_corrupt
```

Rules:

1. quota APIs are advisory only,
2. transactional write failure still fails closed,
3. checksum mismatch on unresolved records sets support-required and blocks ordinary recapture/sync for those records,
4. corruption never auto-deletes unresolved envelopes,
5. capacity block prevents new capture while preserving existing records,
6. cashier messaging is actionable and never recommends clearing browser storage as the fix for unsynced sales.

### R5 - Browser-data clearing behavior

If the cashier or OS clears browser data:

1. local queue and drafts disappear,
2. terminal must re-establish identity before shell use,
3. support playbook distinguishes:
   - cleared before durable capture success,
   - cleared after durable capture but before sync,
   - cleared after accepted tombstone only,
4. no automatic “recreate the missing sale” path from browser memory,
5. server-side imports and sales remain the authority for anything already accepted.

### R6 - Browser uncertain-write-result recovery

If the write result is uncertain (timeout, aborted transaction, process kill mid-write):

1. persistence_state is `capture_uncertain` when partial evidence exists,
2. ordinary duplicate re-entry is blocked when cash may have been collected,
3. record remains support-visible,
4. exact recovery may promote to `durably_captured` only after full read-back and checksum verification,
5. if verification fails, state remains `capture_uncertain` or becomes `storage_failed` with support review required.

### R7 - Local record exists but UI did not show success

On startup or next navigation:

1. scan for `durably_captured` or `capture_uncertain` records not acknowledged in UI session state,
2. present recovery banner: sale was saved locally and needs review/sync status,
3. do not allow the cashier to re-enter the same cash sale as a fresh capture without support classification,
4. preserve original cashier attribution from the envelope.

### R8 - Local success displayed but storage disappears afterward

If UI previously showed local success and later the record is missing:

1. treat as storage-loss after capture,
2. mark terminal queue health `support_required` when detectable,
3. do not claim the sale was officially posted unless server acceptance evidence exists,
4. support playbook checks server import/sale by local UUID / fingerprint when identity is known,
5. if no server match, escalate as cash-collected unresolved without local payload.

### R9 - Queue tombstone exists but full payload is gone

Allowed only when retention policy already compacted an accepted/resolved record.

Behavior:

1. tombstone retains minimum identity fields,
2. no attempt to re-sync a payload that no longer exists,
3. support can prove prior acceptance via tombstone + server sale reference,
4. if a tombstone exists without required server identity fields, classify as support-required data-integrity defect and do not pretend acceptance is complete.

Minimum accepted tombstone fields (from Story 41.2 / Architecture Lock):

```text
offline_transaction_uuid
terminal_id
terminal_binding_epoch
local_sequence
business_payload_fingerprint / accepted fingerprint
server_sale_uuid or server sale reference
server_sale_number where available
official_invoice_number where available
accepted_at / accepted timestamp
final_server_status
receipt_status where available
```

### R10 - Terminal-binding epoch recovery

On rebind/reinstall:

```text
old epoch queue remains old-epoch-owned
new epoch starts fresh sequence space
no silent merge across epochs
support may inspect both only through authorized diagnostics
```

Rules:

1. increment binding epoch on rebind/reinstall,
2. never restart a prior epoch silently,
3. sequence uniqueness remains `terminal_id + terminal_binding_epoch + local_sequence`,
4. sync authorization must reject cross-epoch claim without support workflow.

### R11 - Accepted tombstone recovery

Accepted local records move through:

```text
accepted_retained
accepted_compacted
purged
```

Rules:

1. accepted retained keeps enough local evidence for short support windows,
2. accepted compacted keeps the minimal tombstone,
3. purged removes local residual only after retention policy,
4. purge never removes unresolved, capture-uncertain, review-required, or cash-disputed records solely for age.

### R12 - Cashier changed after queue creation

Rules:

1. original envelope cashier/shift actor fields remain immutable,
2. current signed-in cashier may see aggregate terminal pending counts according to policy,
3. current cashier cannot rewrite actor fields,
4. support extraction shows original cashier and current operator separately,
5. automatic sync continues regardless of which cashier is signed in,
6. shift-close checks unresolved records for the shift, not only the current cashier.

## Support Extraction Contract

### Support queue extraction

Authorized support/manager extraction must provide a diagnostics snapshot that includes:

1. local offline transaction reference / UUID,
2. terminal identity and binding epoch,
3. cashier identity from original envelope,
4. branch and tenant context,
5. sync status / server state / resolution state,
6. retry count and last error code/category,
7. conflict/review reason where present,
8. sync timestamps,
9. idempotency/fingerprint reference,
10. hardware state where relevant,
11. queue owner and lease state,
12. consequence statuses if already known from server responses,
13. provisional versus official receipt state if known,
14. shift and drawer references,
15. cash-collected review and resolution status,
16. storage capability and queue health.

### Masking and export safeguards

Export requires:

1. authorized support role,
2. masked customer data,
3. no credentials, secrets, PINs, or card data,
4. no raw encryption keys,
5. evidence checksum,
6. export timestamp,
7. terminal and epoch context,
8. explicit label: `provisional local evidence`.

Export metadata:

```text
export_id
generated_at
generated_by
terminal_id
terminal_binding_epoch
queue_schema_version
record_count
export_checksum
filter_summary
```

Rules:

1. ordinary cashiers cannot export raw queue payloads,
2. export is a snapshot and must not rewrite or repair local data,
3. export is not a new authoritative ledger record,
4. support extraction with only tombstones must still be possible and clearly labelled payload-absent.

## Hardware Boundary Contract

### Distinction matrix

| Scenario | Classification | Cashier messaging | Release claim |
|---|---|---|---|
| Printer not configured / NoOp adapter | Hardware unavailable | Printing unavailable on this terminal | Deferred unless physical validation later |
| Browser print path only | Hardware available (browser) / limited | Browser print may be used; not certified device print | Not physical printer readiness |
| Physical printer online and print succeeds | Hardware available + success | Receipt sent to printer | Claim only with physical UAT evidence |
| Physical printer online but print fails | Hardware available + failure | Print failed; sale state unchanged | Failure handled; readiness not claimed |
| Cash drawer not configured | Hardware unavailable | Drawer open unavailable | Deferred |
| Drawer open signal fails | Hardware available + failure | Drawer did not open; handle cash carefully | Failure handled; readiness not claimed |
| Scanner unavailable | Hardware unavailable | Manual entry remains available | Not a release blocker by itself |
| Tablet browser-only mode | Baseline mode | Browser-only terminal mode | Explicit non-claim for dedicated hardware |

### Receipt printer validation matrix

Validate and document:

1. no printer configured,
2. browser print adapter path,
3. physical printer unavailable,
4. physical printer available and succeeds,
5. physical printer available and fails mid-print,
6. reprint after online acceptance only through approved receipt flows,
7. offline provisional acknowledgment is never presented as official printed fiscal invoice.

### Cash drawer validation matrix

Validate and document:

1. no drawer configured,
2. browser adapter cannot open drawer,
3. physical drawer unavailable,
4. physical drawer open succeeds,
5. physical drawer open fails,
6. drawer failure does not invent or cancel a sale,
7. offline cash capture remains independent of drawer success.

### Hardware-deferred evidence rules

For pilot without physical devices:

1. record adapter in use (`NoOp`, `BrowserPrint`, future Android bridge),
2. record which scenarios were simulated versus physically tested,
3. mark printer readiness and drawer readiness as `deferred` or `validated`,
4. never publish release language that implies physical hardware readiness when only browser/NoOp paths were exercised,
5. Story 41.8 release gate must consume this evidence explicitly.

### Physical hardware UAT checklist (when devices available)

1. Print sample provisional acknowledgment offline and confirm non-fiscal labeling.
2. Print official receipt only after server acceptance path.
3. Force printer offline and confirm sale/sync state is not falsified.
4. Open cash drawer on paid cash sale where configured.
5. Force drawer failure and confirm operator messaging.
6. Capture evidence: device model, OS, browser/WebView, adapter version, timestamp, operator, pass/fail, notes.
7. Attach evidence to pilot pack; do not rely on verbal confirmation.

## Support Playbook

Minimum support procedures Story 41.7 must document and, where practical, enforce in UI copy:

1. **Pending unsynced records**
   - Do not clear site data.
   - Export diagnostics.
   - Restore connectivity and retry eligible records only.

2. **Capture uncertain**
   - Do not re-enter the sale as ordinary new capture.
   - Inspect local evidence and cash collected state.
   - Open support resolution workflow.

3. **Storage unavailable / corrupt**
   - Block new offline capture.
   - Preserve whatever records remain.
   - Export diagnostics if possible.
   - Escalate; do not “reset terminal” as first action.

4. **Terminal reinstall / identity loss**
   - Re-activate terminal through approved binding flow.
   - Confirm new binding epoch.
   - Treat old-epoch residuals as support review.

5. **Tombstone only**
   - Use tombstone + server sale reference for proof of acceptance.
   - Do not attempt payload replay.

6. **Hardware unavailable**
   - Continue approved browser/manual procedures.
   - Record deferral evidence.
   - Do not claim peripheral readiness.

7. **Hardware failed**
   - Keep sale/sync state authoritative from software path.
   - Retry hardware action only; do not recreate the sale.

## UI and Messaging Rules

Cashier-facing messages must be plain language:

1. “Sale saved on this terminal. Waiting to sync.”
2. “Sale could not be saved safely. Do not assume it is recorded. Call support.”
3. “This terminal needs support before taking more offline sales.”
4. “Printer unavailable.” / “Print failed.” as distinct messages.
5. “Drawer unavailable.” / “Drawer did not open.” as distinct messages.
6. Never: “Clear browser cache to fix offline sales” as default guidance.
7. Never: “Official invoice issued” for local-only provisional capture.
8. Technical fields (fingerprints, lease IDs, checksums, worker versions) remain support-only.

## Acceptance Criteria

### AC1 - Terminal identity loss fails closed

Given terminal context is missing, revoked, or not bound,
when the cashier opens the terminal shell or attempts sync,
then access/sync fails closed with recovery guidance and no orphaned queue is silently claimed.

### AC2 - Reinstall advances binding epoch

Given a terminal is reinstalled or rebound,
when activation completes,
then `terminal_binding_epoch` advances, old-epoch records remain old-epoch-owned, and sequence continuity is not silently forged.

### AC3 - Storage unavailable blocks capture without false success

Given IndexedDB/storage is unavailable,
when the cashier attempts offline cash capture,
then capture fails closed, no success state is shown, and existing recovery guidance does not recommend clearing data as the fix.

### AC4 - Capture-uncertain blocks ordinary re-entry

Given a local write result is uncertain and cash may have been collected,
when the terminal restarts,
then the record is classified `capture_uncertain` or equivalent support-required state and ordinary duplicate recapture is blocked.

### AC5 - Local record without UI success is recovered honestly

Given a durably captured record exists but the prior UI session did not confirm success,
when the terminal loads,
then the cashier/support sees recovery status and the original envelope remains unchanged.

### AC6 - Post-success storage disappearance does not invent acceptance

Given UI previously showed local success and the record later disappears from storage,
when support investigates,
then the system does not claim official acceptance without server evidence and routes to storage-loss support procedure.

### AC7 - Tombstone-only recovery preserves identity without payload replay

Given an accepted compacted tombstone exists and full payload is gone,
when support extracts diagnostics,
then minimum identity and server sale reference remain available and no sync replay of missing payload is attempted.

### AC8 - Support export is authorized and masked

Given an authorized support/manager role,
when queue extraction runs,
then export includes required diagnostic fields, masks sensitive customer data, excludes secrets, and is labelled provisional local evidence.

### AC9 - Ordinary cashier cannot export raw payloads

Given a cashier without support export permission,
when export is attempted,
then access is denied.

### AC10 - Cashier switch preserves original attribution

Given cashier A created a queued envelope and cashier B later signs in,
when diagnostics or sync run,
then actor fields remain cashier A and current operator is recorded separately for support actions only.

### AC11 - Printer unavailable vs print failure are distinct

Given printer not configured versus printer configured but print fails,
when the operator attempts print,
then messaging and evidence classify unavailable and failure separately, and sale/sync state is not falsified.

### AC12 - Drawer unavailable vs open failure are distinct

Given drawer not configured versus drawer open signal fails,
when drawer open is attempted,
then messaging and evidence classify unavailable and failure separately, and sale state is unchanged by drawer outcome.

### AC13 - No hardware readiness claim without physical validation

Given only browser/NoOp adapter paths were exercised,
when pilot evidence is produced,
then printer/drawer readiness are marked deferred or not-validated and release language cannot claim physical hardware readiness.

### AC14 - Service-worker upgrade preserves unresolved queue

Given unresolved offline records exist,
when a new service worker or shell cache version is installed,
then IndexedDB queue evidence remains readable and destructive cache-reset is not the default recovery path.

### AC15 - Quota/corruption cannot report false successful capture

Given storage quota exhaustion or checksum corruption during/after capture,
when capture or startup integrity runs,
then success is not shown for unverified records and queue health becomes blocked or support-required as appropriate.

### AC16 - Support playbook is complete for recovery classes

Given the recovery classes in this story,
when documentation is reviewed,
then each class has an explicit support procedure that preserves original cashier attribution and avoids silent queue ownership transfer.

### AC17 - Binding epoch is server-issued

Given terminal reactivation or rebind,
when the binding is created,
then the server assigns a monotonic epoch and the client cannot select or restore an earlier epoch.

### AC18 - Routine login does not rebind

Given an already bound terminal,
when cashiers log in, log out, lock, reload, or update the service worker,
then the binding epoch does not change.

### AC19 - Recovery scan respects processor ownership

Given a sync processor owns the queue lease,
when startup integrity or support extraction runs,
then it does not mutate records or steal processor ownership.

### AC20 - Queue migration is non-destructive

Given an older supported queue schema,
when migration runs,
then unresolved records are copied, verified, and preserved if migration fails.

### AC21 - Browser print does not imply physical readiness

Given the browser print adapter exists,
when capability is reported,
then it is marked limited and not physically validated.

### AC22 - Site-data loss detection is evidence-qualified

Given a newly activated empty profile,
when prior queue metadata cannot be independently established,
then the system reports possible storage loss rather than claiming confirmed queue loss.

### AC23 - Tombstone compaction is safe

Given an accepted record is eligible for compaction,
when the payload is removed,
then the tombstone has been written, read back, checksummed, and contains required server identity.

### AC24 - Export uses an allowlist

Given support diagnostics are exported,
then only approved fields are included and secret fields cannot appear merely because they were added to a local payload in a future version.

### AC25 - Hardware evidence is consumable by Story 41.8

Given printer/drawer scenarios are tested or deferred,
when pilot evidence is produced,
then each capability has an explicit validation type, result, evidence reference, and residual risk.

## Test Plan

Frontend / queue tests:

1. Missing terminal context fails closed for shell/sync entry points covered by this story.
2. Rebind/reinstall path advances binding epoch and isolates old-epoch records.
3. Storage unavailable blocks offline capture and shows non-success messaging.
4. Capture-uncertain record blocks ordinary recapture and remains visible to diagnostics.
5. Startup recovery surfaces durably captured records not previously UI-acknowledged.
6. Tombstone compaction retains minimum identity fields after payload removal.
7. Diagnostics bundle includes storage capability, tombstone count, and queue health inputs.
8. Support export masking removes/redacts customer secrets and credentials.
9. Unauthorized cashier cannot export raw queue payloads.
10. Cashier switch does not mutate original envelope actor fields.
11. Printer unavailable and printer failure produce distinct statuses/messages.
12. Drawer unavailable and drawer failure produce distinct statuses/messages.
13. Service-worker/cache upgrade path does not delete IndexedDB queue stores.
14. Checksum mismatch on unresolved record sets support-required / blocked behavior.
15. Capacity block prevents new capture while retaining existing records.
16. Server-issued binding epoch cannot be overridden by client-proposed older epoch.
17. Routine cashier login/logout, lock, reload, and service-worker update do not increment binding epoch.
18. Recovery scans do not mutate queue records while sync owns the processor lease.
19. Queue migration copies, verifies, and preserves unresolved records on failure.
20. Browser print capability is reported as limited and not physically validated.
21. Site-data loss without prior external queue metadata reports `possible_storage_loss`.
22. Tombstone compaction verifies required server identity and tombstone checksum before deleting payload.
23. Support export uses an allowlist rather than serializing arbitrary future payload fields.
24. Scanner behavior remains documentation-only pilot evidence and does not introduce a scanner adapter.

Integration / contract tests where server-facing:

1. Sync rejects cross-terminal or unauthorized identity reuse according to existing terminal binding rules.
2. Accepted server sale remains discoverable after local tombstone compaction using local UUID / fingerprint references already established by Stories 41.2–41.3.
3. No new top-level sync status values are introduced by recovery work.

Manual / pilot evidence:

1. Browser-data clear procedure walkthrough with support playbook.
2. Hardware matrix documentation completed for available adapters.
3. Physical device checklist executed or explicitly deferred with evidence record.
4. Support extraction dry-run with masked sample output.

Regression tests:

1. Existing Story 41.2 queue integrity tests continue to pass.
2. Existing Story 41.3 sync contract tests continue to pass.
3. Existing Story 41.4 review-required tests continue to pass.
4. Existing Story 41.5 offline restriction tests continue to pass.
5. Existing Story 41.6 consequence tests continue to pass.
6. Terminal identity binding tests continue to pass.

## Mutation Boundary

What may change:

1. Recovery UI/status messaging.
2. Startup integrity and recovery classification wiring.
3. Support diagnostics export and masking.
4. Hardware status classification and evidence recording.
5. Service-worker upgrade safeguards related to unresolved queue preservation.
6. Documentation: support playbook, hardware matrix, deferred evidence records.

What must not change:

1. Server authority for sale, inventory, loyalty, store credit, fiscal documents.
2. Top-level offline sync acceptance vocabulary from Stories 41.3–41.6.
3. Cash-only offline capture policy and online-only restrictions from Story 41.5.
4. Immutable envelope business payload after durable capture.
5. Exact-replay idempotency and review-required fail-closed behavior.
6. Consequence ownership established in Story 41.6.

## Definition of Done

Story 41.7 is done when:

1. Acceptance criteria pass.
2. Recovery scenario contracts R1–R12 are implemented or explicitly evidenced where manual-only.
3. Support export authorization and masking are implemented and tested.
4. Hardware unavailable versus failure matrices are documented and reflected in adapter/status behavior.
5. Hardware-deferred evidence record exists for pilot.
6. Physical hardware UAT checklist is either executed with evidence or explicitly deferred without readiness claim.
7. Support playbook is written and referenced from operator/support docs.
8. Relevant frontend queue/hardware tests pass.
9. `php artisan test tests/Feature/POS` passes for impacted terminal/sync suites.
10. Targeted frontend tests for queue recovery/diagnostics pass.
11. `npm run build` passes.
12. `git diff --check` passes.
13. Code review confirms no silent epoch reuse or orphan claim.
14. Code review confirms no false accepted-sale evidence from storage-loss paths.
15. Code review confirms no hardware readiness claim without physical validation evidence.
16. Code review confirms original cashier attribution is preserved after cashier switch and support extraction.
17. Story index and implementation guide status are updated.
18. Residual deferrals are explicitly listed for Story 41.8 release gate consumption.

## Implementation Notes

Important cautions:

1. Do not treat browser storage as the official ledger during recovery.
2. Do not auto-delete corrupt or uncertain records to “clean” the queue.
3. Do not recommend clearing site data while unresolved records may exist.
4. Do not merge old-epoch queue ownership into a new epoch without explicit support workflow and Architecture Lock revision if needed.
5. Do not make `BrowserPrintAdapter` or `NoOpHardwareAdapter` imply physical printer/drawer readiness.
6. Do not open a cash drawer failure path that voids or recreates a sale.
7. Do not expand sync acceptance rules under the guise of recovery.
8. Prefer extending `OfflineSalesQueueService` diagnostics and existing adapter contracts over inventing a second offline store.
9. Keep cashier UI simple; put technical recovery detail in support diagnostics.
10. When only tombstones remain, support proof is identity + server reference, not payload reconstruction.
11. Preserve Story 41.2 lease/single-writer rules when adding recovery scans or export.
12. Any new recovery status shown to cashiers must map cleanly to existing persistence/queue/server/resolution states rather than inventing conflicting vocabularies.

## Implementation Review Guidance

These items are non-blocking architecture notes, but implementation PRs should address them explicitly.

### Recovery State Dimensions

Do not flatten recovery, storage, ownership, persistence, and sync concepts into one combined enum.

Preferred projection shape:

```text
storage_state = storage_corrupt
queue_health = support_required
persistence_state = capture_uncertain
ownership_state = current_epoch_owned
sync_status = pending
recovery_reason = checksum_mismatch
```

Avoid combined values such as:

```text
corrupt_capture_uncertain_support_pending
```

### Queue Maintenance Lease

State promotion, migration, integrity repair, and compaction must use a mutating queue lease purpose rather than silently reusing sync ownership if the existing lease model does not support maintenance work.

Recommended lease purposes:

```text
sync
maintenance
migration
compaction
```

Only one mutating lease may exist for the same terminal epoch at a time. Read-only diagnostics and exports should not require a mutating lease.

### Queue-Health Heartbeat Privacy

Queue-health heartbeat metadata must define:

1. minimum reporting interval,
2. offline retry behavior,
3. retention period,
4. tenant and terminal scope,
5. whether unresolved count is exact or bucketed,
6. authorization required to view history.

Heartbeat failure must not block local capture when local storage and terminal authorization remain valid.

### Shared Canonicalization Modules

Checksum verification must reuse the same versioned canonicalization logic across:

1. initial capture,
2. startup integrity scan,
3. migration,
4. tombstone creation,
5. support export.

Suggested interfaces:

```ts
canonicalizeOfflineEnvelope(payload, version)
canonicalizeOfflineTombstone(tombstone, version)
```

Envelope and tombstone canonicalization remain separate contracts.

### Local Tombstone Compaction Atomicity

Local compaction should be performed in one IndexedDB transaction where possible:

```text
write verified tombstone
mark payload compacting
delete full payload
mark tombstone accepted_compacted
```

If deletion fails, retaining both payload and tombstone is safer than retaining neither.

### Recovery State Placement

`possible_storage_loss` belongs to terminal recovery or queue-health classification, not the top-level sync status.

Preferred:

```text
terminal_recovery_state = possible_storage_loss
```

Avoid:

```text
sync_status = possible_storage_loss
```

### Browser Print Evidence

For `BrowserPrintAdapter`, record:

```text
print_dialog_requested
```

or:

```text
browser_print_invoked
```

Do not record physical print success unless confirmed by a validated native adapter or physical UAT observation.

### Support Export Scope

Support exports should be bounded by first-release filters:

```text
status
time range
offline transaction UUID
local sequence range
cash exposure
epoch
```

Reject overly broad requests rather than silently truncating them.

### UI Acknowledgment Evidence

Recovery banners for records not acknowledged in the prior UI session should use lightweight local event evidence instead of mutating the immutable envelope:

```text
offline_capture_ui_acknowledged
offline_transaction_uuid
acknowledged_at
session_id
cashier_id
```

### Adapter-Specific UAT Expectations

Physical UAT evidence must reflect adapter capability:

| Adapter | Valid claim |
| --- | --- |
| `NoOp` | Hardware unavailable path works |
| `BrowserPrint` | Dialog invocation and non-fiscal document layout |
| Native printer bridge | Device connection, acknowledgment, print success/failure |
| Native drawer bridge | Drawer signal success/failure |

### Recommended Delivery Order

Implementation should proceed in this order:

1. Recovery-state and ownership model.
2. Server-issued epoch and rebind contract.
3. Storage capability, checksums, and queue health.
4. Queue migration and service-worker compatibility.
5. Recovery events, tombstones, and compaction.
6. Diagnostics projection and support export.
7. Hardware capability classification.
8. Hardware validation evidence and Story 41.8 handoff.
9. Support playbook and regression testing.

### Critical PR Review Invariants

Implementation PRs should not be approved unless these invariants hold:

```text
local capture success
    implies write completed
    and read-back succeeded
    and checksum matched
```

```text
server acceptance claim
    implies accepted server import or sale evidence exists
```

```text
terminal rebind
    implies new server-issued epoch
    and no old-epoch ownership transfer
```

```text
queue compaction
    implies final server status
    and verified tombstone
    and required server identity
    and no support hold or cash dispute
```

```text
hardware operation failure
    implies sale and sync state remain unchanged
```

```text
support extraction
    implies no envelope mutation
    no ownership rewrite
    no secret leakage
```

## Locked Implementation Decisions

1. **Recovery owner:** terminal client queue integrity + support diagnostics; server remains authority for accepted sales.
2. **Epoch rule:** reinstall/rebind advances epoch; old epoch never silently restarts.
3. **False-success rule:** no success UI without durable verified local capture; no official acceptance claim without server evidence.
4. **Uncertain capture rule:** block ordinary re-entry; require support classification.
5. **Tombstone rule:** minimum identity retained; payload may be absent only after accepted/resolved retention policy.
6. **Support export rule:** authorized, masked, checksummed, provisional-labelled snapshot only.
7. **Hardware rule:** unavailable ≠ failed; readiness is evidence-based and may be deferred.
8. **Service-worker rule:** upgrade may replace shell assets, must not destroy unresolved queue evidence, and must not race foreground sync.
9. **Cashier attribution rule:** original envelope actor is immutable after capture.
10. **Release language rule:** Story 41.7 may validate recovery and document hardware boundaries, but physical readiness claims require physical evidence and feed Story 41.8.

## Deliverables Checklist

- [ ] Terminal reinstall recovery specification implemented/enforced
- [ ] Storage-loss recovery specification implemented/enforced
- [ ] Service-worker upgrade / stale worker / cache recovery safeguards
- [ ] IndexedDB corruption and quota behavior
- [ ] Browser-data clearing behavior and messaging
- [ ] Uncertain-write-result recovery
- [ ] Local-record-without-UI-success recovery
- [ ] Local-success-then-storage-loss recovery
- [ ] Tombstone-without-payload recovery
- [ ] Terminal-binding epoch recovery
- [ ] Accepted tombstone recovery path
- [ ] Support queue extraction
- [ ] Masked support export
- [ ] Cashier-changed attribution preservation
- [ ] Receipt printer validation matrix
- [ ] Cash drawer validation matrix
- [ ] Hardware-deferred evidence record
- [ ] Physical hardware UAT checklist (executed or deferred)
- [ ] Support playbook

## Handoff to Story 41.8

Story 41.8 Pilot UAT and Release Gate must consume:

1. recovery scenario evidence from this story,
2. hardware validated-versus-deferred matrix,
3. support playbook operability,
4. any residual storage or peripheral risks explicitly listed as go/no-go inputs.

Story 41.7 does not itself make the branch rollout release decision.
