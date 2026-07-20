# Epic 41 Evidence Manifest

Date: 2026-07-18
Status: Template - Pending Pilot Execution
Related Story: `docs/implementation-plans/epic-41/stories/story-41.8-pilot-uat-and-release-gate.md`

## 1. Purpose

This manifest defines the required evidence shape for Epic 41 pilot UAT and release decisions.

An artifact being captured is not enough to pass a scenario. Only evidence with `evidence_status = accepted` can support a passed release gate.

## 2. Evidence Status Values

```text
captured
under_review
accepted
rejected
superseded
expired
```

Rejected, superseded, or expired evidence remains in history. Do not delete failed or replaced evidence from the decision trail.

## 3. Evidence Manifest Fields

| Field | Required | Notes |
| --- | --- | --- |
| `evidence_id` | Yes | Stable evidence reference |
| `scenario_id` | Yes | Stable scenario ID |
| `scenario_version` | Yes | Scenario version executed |
| `contract_version` | Yes | Release contract version executed |
| `artifact_type` | Yes | screenshot, export, log, signed note, test output, print sample |
| `artifact_location` | Yes | File path, external evidence repository reference, or ticket reference |
| `artifact_version` | Yes | Version of evidence artifact if updated |
| `captured_by` | Yes | Person or role |
| `captured_at` | Yes | Timestamp with timezone |
| `reviewed_by` | Yes | Independent reviewer where required |
| `reviewed_at` | Conditional | Required before `accepted` |
| `environment_id` | Yes | Must match environment manifest |
| `application_build` | Yes | Build identifier |
| `git_commit` | Yes | Commit tested |
| `deployment_id` | Conditional | Required where deployment pipeline exists |
| `queue_schema_version` | Yes | Queue contract tested |
| `sync_contract_version` | Yes | Sync contract tested |
| `service_worker_version` | Yes | Service worker tested |
| `tenant_id_or_alias` | Yes | Alias preferred in public docs |
| `branch_id_or_alias` | Yes | Alias preferred in public docs |
| `terminal_id_or_alias` | Conditional | Required for terminal scenarios |
| `terminal_binding_epoch` | Conditional | Required for queue/sync/recovery scenarios |
| `cashier_id_or_alias` | Conditional | Required for cashier scenarios |
| `offline_transaction_uuid` | Conditional | Required for transaction-specific evidence |
| `local_sequence` | Conditional | Required for queue-order evidence |
| `server_import_reference` | Conditional | Required after sync attempt |
| `server_sale_reference` | Conditional | Required after accepted sale |
| `official_invoice_reference` | Conditional | Required when official invoice retrieval is validated |
| `contains_sensitive_data` | Yes | yes/no |
| `masking_status` | Yes | not_needed, masked, restricted, rejected |
| `retention_class` | Yes | See retention classes |
| `checksum` | Optional | Recommended for exported files |
| `checksum_algorithm` | Conditional | Required when checksum exists |
| `evidence_status` | Yes | Captured through accepted lifecycle |
| `notes` | Optional | Observation or limitation |

## 4. Environment Manifest

| Field | Required | Value |
| --- | --- | --- |
| `environment_id` | Yes |  |
| `application_build` | Yes |  |
| `git_commit` | Yes |  |
| `deployment_id` | Conditional |  |
| `migration_version` | Yes |  |
| `queue_schema_version` | Yes |  |
| `sync_contract_version` | Yes |  |
| `browser_version` | Yes |  |
| `service_worker_version` | Yes |  |
| `terminal_id_alias` | Yes |  |
| `terminal_binding_epoch` | Yes |  |
| `hardware_adapter` | Yes |  |
| `printer_model` | Conditional |  |
| `drawer_model` | Conditional |  |
| `network_profile` | Yes | online, offline, intermittent, throttled, simulated |
| `feature_policy_version` | Yes |  |
| `catalog_snapshot_version` | Conditional |  |
| `shift_policy_version` | Conditional |  |
| `business_date_rule_version` | Conditional |  |

## 5. Pilot Scope Manifest

| Field | Required | Value |
| --- | --- | --- |
| `pilot_scope_id` | Yes |  |
| `scope_version` | Yes |  |
| `tenant_aliases` | Yes |  |
| `branch_aliases` | Yes |  |
| `terminal_aliases` | Yes |  |
| `binding_epochs` | Yes |  |
| `cashier_roles` | Yes |  |
| `start_date` | Yes |  |
| `end_date` | Yes |  |
| `build_reference` | Yes |  |
| `feature_policy_reference` | Yes |  |
| `test_data_policy` | Yes |  |

Changes to pilot branch, terminal, build, policy, cashier scope, or business-date scope require a new scope version.

## 6. Retention Classes

| Retention Class | Definition |
| --- | --- |
| `release_record` | Retained with the release decision |
| `pilot_operational` | Retained through pilot plus configured support period |
| `sensitive_diagnostic` | Short retention with restricted access |
| `hardware_validation` | Retained while hardware configuration remains approved |
| `temporary_test` | Deleted after review and approval |

## 7. Hardware Evidence Validity

Accepted physical hardware evidence must include:

| Field | Required |
| --- | --- |
| `evidence_valid_until` | Yes |
| `invalidated_by_change` | Yes |
| `device_model` | Yes |
| `operating_system_or_webview` | Yes |
| `browser_version` | Yes |
| `adapter_version` | Yes |
| `printer_model` | Conditional |
| `drawer_model` | Conditional |
| `connection_method` | Conditional |
| `receipt_template_version` | Yes |

Evidence becomes invalid when relevant device, OS, browser, adapter, printer, drawer, connection method, or receipt template changes.

## 8. Evidence Register

| Evidence ID | Scenario ID | Scenario Version | Artifact Type | Artifact Location | Environment ID | Evidence Status | Reviewed By | Retention Class | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| EV-41-0001 |  |  |  |  |  | captured |  |  |  |
