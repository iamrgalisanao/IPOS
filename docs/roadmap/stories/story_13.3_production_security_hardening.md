# Story 13.3: Production Security Hardening

Status: Completed 2026-05-13

Implementation status summary:

- Slice A: Implemented 2026-05-13
- Slice B: Implemented 2026-05-13
- Slice C: Implemented 2026-05-13
- Slice D: Implemented 2026-05-13
- Slice E: Implemented 2026-05-13

Validated baseline as of 2026-05-13:

- `tests/Feature/Security/SecurityHeadersTest.php`
- `tests/Feature/Security/ProductionSecretExposureTest.php`
- `tests/Feature/Security/SensitiveRouteProtectionTest.php`
- `tests/Feature/Security/ProductionConfigurationGuardTest.php`
- full `php artisan test tests/Feature/Security` (`16 passed`, `90 assertions`)
- full `php artisan test` (`719 passed`, `1 risky` baseline)

## Goal

Reduce the risk of production misconfiguration, secret exposure, unsafe debug state, and overly permissive operational access without redesigning the existing application architecture.

## Implementation Boundaries

Implement only:

1. Production configuration guardrails for unsafe runtime defaults.
2. Focused validation of sensitive route protections already in scope.
3. Secret redaction consistency checks for diagnostics and provider-facing errors.
4. Production deployment checklist and rollback guidance.
5. Tests proving support-assisted mode and sensitive flows do not bypass write protections.

Do not implement yet:

- third-party security vendors
- new operational dashboards
- new provider-side actions or reconnect tooling
- tenant-facing feature redesigns
- broad authorization rewrites unrelated to sensitive routes already in scope

## Story 13.3 Boundary Lock

This story hardens existing runtime defaults and sensitive control paths. It must not widen support powers, bypass tenant isolation, or add new write-side behavior.

## Implementation Slice Order

### Slice A: Production Configuration Guardrails

Status: Implemented 2026-05-13

Implement:

- bootstrap-time guardrails for unsafe non-local configuration
- fail-fast protection when debug mode is enabled outside local or testing
- fail-fast protection when session cookies are not marked secure outside local or testing
- fail-fast protection when session cookies are not HTTP-only outside local or testing

Validation:

- focused security test proving unsafe non-local config throws clearly
- focused security test proving local and testing environments remain exempt

### Slice B: Sensitive Route Protection Review

Status: Implemented 2026-05-13

Implement:

- focused review of support, accounting, profile, and export route protections
- missing permission or CSRF assertions only where repository evidence shows a gap

Implemented outcome:

- accounting outbox API routes now require the same `view_sync_dashboard` permission boundary as the web dashboard surface
- settlement export authorization now explicitly rejects tenant-mismatched periods in addition to existing branch and permission checks
- focused security coverage now proves guest, unauthorized, support-assisted, profile, and export boundaries fail closed on the reviewed route surfaces

### Slice C: Secret Redaction Consistency

Status: Implemented 2026-05-13

Implement:

- cross-surface checks that logs and diagnostics do not leak tokens, provider payloads, or secrets
- tiny refactors only if an existing sanitizer leaves an exposed fragment

Implemented outcome:

- support audit review responses now redact string-embedded credential and environment fragments such as `Authorization`, `Bearer`, `access_token`, `refresh_token`, `client_secret`, `APP_KEY`, and password-style config secrets
- QuickBooks callback and provider error sanitizers now redact the same expanded secret fragment set without introducing a second redaction path
- focused Slice C security coverage now proves support audit review and callback-facing error responses fail closed on fake secret material

### Slice D: Production Checklist

Status: Implemented 2026-05-13

Implement:

- deployment checklist for production hardening
- rollback expectations tied to runtime config changes

Implemented outcome:

- low-risk security headers are now centralized through HTTP middleware for both web and API responses
- `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, and `Permissions-Policy` are now applied with conservative defaults
- HSTS is applied only for secure non-local, non-testing requests
- CSP was intentionally deferred in this slice because the current Inertia/Vite frontend dependency surface was not re-profiled here, and a guessed CSP policy would risk breaking production behavior without adding proportional security confidence

### Slice E: Final Validation Closure

Status: Implemented 2026-05-13

Implement:

- focused regression on Story 13.3 test surfaces
- full backend test suite
- frontend build validation

Implemented outcome:

- the full Story 13.3 security suite passed without requiring an additional attestation test file because the existing Slice A-D tests already covered the completed baseline coherently
- full backend regression remained green with the unchanged one-test risky baseline
- frontend build was not applicable for Slice E because no frontend files, CSP behavior, route links, layouts, or compiled assets changed during the closure pass

## Story 13.3 Closure Attestation

Story 13.3 is complete.

The production security hardening baseline now includes:

- fail-fast production configuration checks
- sensitive route protection for support, accounting, profile, and export surfaces
- strengthened secret and environment redaction across support and provider-facing error paths
- centralized low-risk security headers for web and API responses
- documented CSP deferral pending a dedicated frontend-safe policy review

## Required Test Coverage

Primary Slice A test file:

- `tests/Feature/Security/ProductionConfigurationGuardTest.php`

Required initial coverage:

- non-local debug mode is rejected
- non-local insecure session cookie config is rejected
- non-local non-HTTP-only session cookie config is rejected
- local environment is exempt
- testing environment is exempt

## Validation Attestation Format

- focused Slice A security tests
- later Story 13.3 targeted security tests
- full `php artisan test`
- `npm run build`