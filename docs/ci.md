# CI

IPOS uses GitHub Actions as a PR checkpoint gate. The workflow lives at:

```text
.github/workflows/ci.yml
```

## Gates

The Phase 1 CI gate runs on pull requests and selected branch pushes:

```text
composer install
Laravel environment bootstrap
SQLite migration check
route registration check
Pest test suite
npm ci
Vite production build
```

CI green means the branch is mergeable after manual checkpoint review. It does not deploy.

## Local Mirror

Run the local mirror before pushing CI changes:

```bash
scripts/ci-local.sh
```

The test suite needs an explicit PHP memory setting because the local default 128 MB limit is too low for the current full Pest graph.

## Secrets

No GitHub secrets are required for Phase 1 CI.

## Later

Add these only when the product process is ready:

```text
Playwright UAT
flaky burn-in
static analysis
security audits
staging deployment
production deployment with manual approval
```
