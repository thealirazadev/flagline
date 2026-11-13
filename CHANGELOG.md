# Changelog

## Unreleased

### Added
- Phase 3: targeting rules with clauses (`eq`, `in`, `contains`, `semver_*`), a no-JS rule
  builder with add/remove clause rows and up/down reordering, percentage rollouts for rules
  and the fallthrough (basis points summing to 10000), a reshuffle warning when a save
  lowers a weight, and rules published in the ruleset document.
- The Phase 2 distribution pieces that were missing: `GET /api/v1/ruleset` with SDK key
  auth, the JSON error envelope, ETag/304 revalidation, a per-key rate limit, the kill
  switch with synchronous publish, the `app:prune-rulesets` retention command, and a test
  for the publish-conflict retry.
- CI on PHP 8.2, 8.3, and 8.4 with an 85% coverage gate.

### Fixed
- `laravel/framework` 12.64.0 had a critical TokenGuard advisory; upgraded to 12.69.0 with
  patched `league/commonmark`, `league/flysystem`, and Symfony HTTP packages.
