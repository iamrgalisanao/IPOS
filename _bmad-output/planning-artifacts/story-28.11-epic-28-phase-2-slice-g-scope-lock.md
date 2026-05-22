# Story 28.11 — Epic 28 Phase 2 Slice G Scope Lock

Status: Implemented & Locally Validated
Scope: POS Offline Transaction Queue & Sync UX

Implementation complete. Validation passed for targeted frontend queue/sync tests, bootstrap cache feature coverage, and production frontend build.

## Goal
Implement the terminal-side offline queue and cashier-facing sync management UX for controlled offline sales.

## In Scope

### 1. Offline Sales Queue
Create:

`resources/js/POS/offline/offlineSalesQueue.ts`

Responsibilities:
- append provisional transaction envelopes
- compute canonical payload hash
- maintain previous/current hash-chain metadata
- assign terminal-bound provisional sequence number only when allowed
- persist queued records in IndexedDB
- preserve local statuses:
  - queued
  - syncing
  - accepted
  - duplicate
  - rejected
  - conflict
  - failed
  - cancelled
- prevent mutation of original payload/hash/sequence after append

Queue records are append-only.

Once a provisional offline transaction envelope is created:
- original payload cannot be edited
- payload hash cannot be changed
- row hash cannot be changed
- sequence number cannot be changed
- corrections must be created as a new queued event or marked as cancelled before sync

Offline transaction envelopes must store totals using decimal strings or integer centavos.
No raw JavaScript floating-point totals may be stored as authoritative values.

Allowed local statuses lifecycle:
queued → syncing
syncing → accepted
syncing → duplicate
syncing → rejected
syncing → conflict
syncing → failed
queued → cancelled
failed → syncing

Blocked:
accepted → queued
accepted → cancelled
rejected → queued
conflict → queued

### 2. Offline Eligibility Guard
Before provisional offline capture, validate:
- controlled offline sales is enabled
- terminal offline setting is enabled
- tenant and branch setting are enabled
- terminal has `offline_sequence_prefix`
- terminal status is `active`
- PTU/MIN/profile cache is not stale
- cache has usable tax configuration hash

The frontend may assign a terminal-bound provisional offline sequence number only if:
- controlled offline sales is enabled
- terminal has a valid offline_sequence_prefix
- terminal sequence status is active
- PTU/MIN profile cache is not stale
- offline sequence state exists in the local metadata cache

If terminal registration cache is older than 72 hours, provisional offline checkout is blocked and cashier is asked to reconnect.

If any check fails:
- fallback to Phase 1 behavior
- cart draft only
- checkout locked while offline

The assigned sequence remains pending server reconciliation and must not be labeled as BIR-approved or final official invoice until accepted by the server.

### 3. POS Sync Manager
Implement sync behavior:
- detect queued offline transactions
- batch queued items for `/api/pos/offline-sync`
- send only when online
- include batch reference
- include queued imports
- include client totals required by backend
- handle `202`, `200`, `422`, `401`, `403`, and network failure
- mark local records according to server response
- preserve conflict/rejected/failed items for review
- allow manual retry for failed items

### 4. POS Sync Status Panel
Show:
- queued count
- syncing count
- accepted count
- duplicate count
- rejected count
- conflict count
- failed count
- last sync attempt
- last successful sync
- manual retry button
- clear warning when sync fails

### 5. Feature Flag Behavior
If controlled offline sales is disabled:
- keep Phase 1 behavior
- cart draft only
- checkout locked offline

If enabled:
- allow provisional offline transaction capture
- show pending-sync warning
- do not print official receipt in this story

## Out of Scope

Do not implement:
- official receipt printing
- provisional receipt print layout
- BIR-certified receipt wording
- local official GCT
- local official Z-read
- local official e-journal finalization
- admin reconciliation UI
- server-side posting changes
- accepting client totals as official truth

## Required UI Wording

When capturing offline:

```text
OFFLINE TRANSACTION CAPTURED
Pending server synchronization and reconciliation.
This is not final ledger posting.
```

When sync fails:

```text
Sync failed. Transactions remain safely queued on this terminal.
Reconnect and retry synchronization.
```

When conflict/rejected is returned:

```text
Some offline transactions require admin review before posting.
```

## Required Tests

Frontend tests should cover:

1. Queue appends a provisional transaction envelope.
2. Queue record payload/hash/sequence is immutable after append.
3. Hash chain is generated across multiple queued records.
4. Tampering with payload breaks hash verification.
5. Offline capture is blocked when controlled offline sales is disabled.
6. Offline capture is blocked when terminal prefix is missing.
7. Offline capture is blocked when PTU/MIN cache is stale.
8. Offline capture is allowed when all guards pass.
9. Sync manager sends queued items only when online.
10. `202` response marks imports according to server status.
11. `200` idempotent replay does not duplicate local records.
12. `422` keeps records visible as failed/rejected.
13. Network failure keeps records queued/failed and retryable.
14. Sync status panel displays queued/accepted/conflict/rejected counts.
15. No official receipt/GCT/Z-read/e-journal local finalization occurs.

## Documentation / Closure Notes

After implementation, update:

* Story 28.11 scope lock
* Epic 28 roadmap
* validation report if needed

Required governance wording:
Story 28.11 implements terminal-side provisional offline queue and sync UX only. Final sales, inventory, payments, GCT, Z-read, and e-journal finalization remain server-side.

## Validation Evidence

```text
node --test tests/Frontend/catalogCache.test.js tests/Frontend/offlineQueueSync.test.js
Pass (14 assertions / checks passed across the focused frontend suite)

./vendor/bin/pest tests/Feature/POS/OfflineBootstrapCacheTest.php
Pass (5 tests / 30 assertions)

npm run build
Pass
```
