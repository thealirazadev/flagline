# Changelog

## Unreleased

### Added
- Targeting rules with clauses (`eq`, `in`, `contains`, `semver_*`), a no-JS rule
  builder with add/remove clause rows and up/down reordering, percentage rollouts for rules
  and the fallthrough (basis points summing to 10000), a reshuffle warning when a save
  lowers a weight, and rules published in the ruleset document.
- Ruleset distribution: `GET /api/v1/ruleset` with SDK key
  auth, the JSON error envelope, ETag/304 revalidation, a per-key rate limit, the kill
  switch with synchronous publish, the `app:prune-rulesets` retention command, and a test
  for the publish-conflict retry.
- CI on PHP 8.2, 8.3, and 8.4 with an 85% coverage gate.

### Fixed
- A publish that lost its version race and retried could silently drop the change it was
  publishing (a kill, a state or flag edit, an archive, a rule edit, move or delete), because
  the retry saved models that still held the rolled back values. Mutations now re-read first.
- `app:create-environment` left existing flags without state rows in the new environment, so
  they could not be edited there and were missing from its document; they now start off and
  are published.
- Rules of an archived flag could still be moved or deleted.
- `If-None-Match` now uses the weak comparison and accepts `*`, so a client behind a
  compressing proxy still gets 304s.
- The SDK route lists the throttle before key authentication, so guesses at unknown keys are
  rate limited by declaration rather than by middleware priority sorting.
- `laravel/framework` 12.64.0 had a critical TokenGuard advisory; upgraded to 12.69.0 with
  patched `league/commonmark`, `league/flysystem`, and Symfony HTTP packages.
