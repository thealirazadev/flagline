# Testing - flagline

## Strategy

- **Automated first, manual second.** Every feature ships with automated tests in the same
  commit series; the manual checklists in `docs/phases.md` cover what automation can't observe
  well (live cross-SDK polling, keyboard passes, real propagation timing).
- **Three independent suites.** The Laravel app (Pest on PHPUnit, in-memory SQLite with
  `RefreshDatabase`), the PHP SDK (Pest, standalone in `sdks/php`), and the TypeScript SDK
  (Vitest + `tsc --strict`, standalone in `sdks/typescript`). No suite imports another's code.
- **The vectors are the contract.** `sdks/test-vectors/bucketing.json` and `evaluation.json`
  are consumed byte-identically by both SDK suites. A vector failure is never fixed by editing
  the vector; it is fixed in the SDK that diverged from the spec (vector changes need owner
  sign-off, per `docs/rules.md`).
- **Fakes over network.** App tests use `Http::fake` where relevant and never make real
  outbound calls. SDK fetcher tests run against a local in-process stub server scripted to
  return 200/304/401/500, timeouts, tampered bodies, and unknown schema versions. Clocks are
  faked for poll-interval and propagation-bound tests.

## What gets covered where

Unit (app): `RulesetBuilder` (variant index mapping, archived exclusion, empty environment,
rollout serialization), `RulesetSigner` (sign/verify, tamper, wrong secret, constant-time
compare), `KeyGenerator` (format, uniqueness).

Feature (app): auth (login, throttle, logout, guests, no registration); flag CRUD and
validation (key pattern, immutability, variant rules); per-environment state isolation;
rule builder round-trips (add/edit/delete/reorder, priority resequencing, weight sum
validation); kill/restore; audit rows for every mutation (same-transaction assertion);
ruleset API (200 with headers, 304 on ETag match, 401, 405, 429, version-0 document);
publish atomicity (forced builder failure rolls back config and audit); version conflict
retry (two racing publishes, no duplicate or skipped version); prune boundaries.

Unit (SDKs, both): bucketer against all bucketing vectors plus algorithm reference values;
semver parse/compare including prerelease ordering; evaluator against all evaluation vectors;
signature verify accept/reject.

Integration (SDKs, both): fetcher against the stub server (ETag flow, degrade on every failure
class, jitter bounds, no throw from `variation` ever); client lifecycle (`not_ready` before
first fetch, last-good after server death, recovery after restart); kill propagation within
`poll + jitter + timeout` under a fake clock.

End-to-end (manual, phase checklists): one live server, both SDKs polling; 20 scripted
contexts produce identical variants in both; a live kill propagates within the bound; a clean
clone passes every suite using only README commands.

## Exact commands

```bash
# App: full suite, single file, formatting
php artisan test
php artisan test tests/Feature/RulesetApiTest.php
./vendor/bin/pint --test

# PHP SDK
cd sdks/php && composer install && ./vendor/bin/pest

# TypeScript SDK
cd sdks/typescript && npm ci && npm test && npx tsc --noEmit

# Cross-SDK parity (Phase 5)
./sdks/parity.sh
```

First-time app setup:

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan app:create-user you@example.com
```

## CI plan

GitHub Actions on push and pull request to `main`, three independent jobs so an SDK failure is
attributable at a glance:

1. **app**: PHP 8.2; `composer install`, `./vendor/bin/pint --test`, `php artisan test`.
2. **sdk-php**: PHP 8.2; `composer install` and Pest inside `sdks/php`.
3. **sdk-ts**: Node 18 and 20 matrix; `npm ci`, `npx tsc --noEmit`, `npm test` inside
   `sdks/typescript`.

The parity script runs inside both SDK jobs (it only needs the vectors plus that job's SDK).
CI lands after Phase 5; until then the same commands run locally per the phase checklists.

## Definition of "done" for a feature

1. `./vendor/bin/pint --test` clean (and `tsc --noEmit` for TS changes).
2. The owning suite green, new tests included; vector-touching changes green in BOTH SDKs.
3. The feature's manual checklist items in `docs/phases.md` pass.

After creating or editing files, run the relevant suite and fix all errors before reporting
done. One commit per feature, in the order listed in `docs/phases.md`.
