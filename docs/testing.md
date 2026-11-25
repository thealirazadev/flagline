# Testing - flagline

## Strategy

- **Automated first.** Every feature ships with Pest tests (on PHPUnit) for the Laravel app. They
  run against in-memory SQLite (`phpunit.xml` sets `DB_CONNECTION=sqlite`,
  `DB_DATABASE=:memory:`) with `RefreshDatabase`, so the suite needs no running database.
- **Feature tests over the HTTP surface.** Most tests drive the dashboard and the API through real
  requests, then assert on the stored config, the audit rows and the published ruleset document,
  because the guarantees that matter (atomic publish, versioning, signing) are only visible end to
  end.
- **No network.** The suite makes no outbound calls.
- **Manual checks** cover what automation does not observe well: keyboard passes through the
  dashboard and timing a kill against a live polling client.

## What gets covered

Unit: `KeyGenerator` (SDK key and signing secret format, length, no repeats).

Feature:
- Auth: login, wrong password with a safe error, validation, throttling per email and IP, logout,
  guests redirected to the login page, no registration route.
- Flag CRUD and validation: boolean flags get exactly `true`/`false`, string flags keep variant
  order and need at least two distinct variants, key pattern and length, duplicate keys, name
  length, key and type never updated, archive hides the flag and makes it read-only, index search
  and filters, the no-JS variant round trip.
- Per-environment state: new flags default to off with `false` off and `true` fallthrough, changes
  in one environment leave the others alone, variants from another flag are rejected, archived
  flags refuse state changes.
- Rule builder: add, reorder and delete rules with priorities resequenced and one version bump per
  step, a stale double delete, rollout weights that must sum to 10000, rollout serves by variant
  index, semver clause validation, forged operators and oversized values, clause rows added and
  removed without saving, and the weight-lowering warning.
- Kill switch: kill and restore publish synchronously and are audited; killing an already killed
  flag publishes nothing.
- Audit trail: one row per mutation with before and after snapshots, no row for a rejected
  mutation, filters, empty states, pagination.
- Ruleset API: 200 with ETag and a signature over the exact bytes, 304 on a matching ETag, a stable
  version-0 document for a fresh environment, a dashboard change visible on the next fetch, the
  error envelope for bad keys, methods and paths, and per-key rate limiting.
- Publishing: one retry when another publisher takes the version first, no retry for a conflict
  the mutation raised.
- Retention: `app:prune-rulesets` keeps the newest versions and still serves the latest, and
  leaves environments under the limit alone.

The PHP and TypeScript SDKs, and the shared test vectors both will be checked against, are not in
the repository yet, so there are no SDK suites.

## Exact commands

```bash
# Full suite, one file, formatting
php artisan test
php artisan test tests/Feature/RulesetEndpointTest.php
./vendor/bin/pint --test

# What CI runs for tests (needs the pcov or xdebug extension for coverage)
./vendor/bin/pest --coverage --min=85
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

## CI

`.github/workflows/ci.yml` runs on every push and pull request to `main`, as a matrix over PHP 8.2,
8.3 and 8.4 with the `pdo_sqlite` and `sqlite3` extensions and pcov for coverage:

1. `composer install --no-interaction --prefer-dist --no-progress`
2. `vendor/bin/pint --test`
3. `vendor/bin/pest --coverage --min=85` (fails below 85 percent coverage of `app/`)

## Before merging

1. `./vendor/bin/pint --test` clean.
2. The suite green, with new tests for the behavior that changed.
