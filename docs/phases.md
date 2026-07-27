# Phases - flagline

**Rule: phase N+1 does not start until the owner approves phase N.** Phases are ordered
smallest-useful-shippable first; each ends green (app runs, tests pass, Pint clean, logs quiet).
One commit per feature/task, Conventional Commits, in the listed order.

The senior differentiators are hard requirements placed early: the atomic publish transaction
and signed versioned distribution land in Phase 2; the deterministic bucketing spec and its
shared test vectors land in Phase 4 before either SDK evaluator is considered done; cross-SDK
parity is the exit gate of Phase 5. None of these may slip.

---

## Phase 1 - Foundation, environments, flags, and the audit trail

**Goal**: An operator can log in, see production and staging, create boolean and string flags
with variants, set per-environment state (enabled, off variant, fallthrough variant), and see
every change in the audit trail. No distribution yet; smallest slice that is already a usable
flag registry.

### Scope and tasks

- Scaffold Laravel 11 with SQLite default; `database` drivers for session/cache; no queue.
  `.env.example` current; `config/flagline.php` holds the env-backed settings.
- Migrations, models, and factories: `environments`, `flags`, `variants`, `flag_environments`,
  `audit_logs` exactly as specified in `docs/architecture.md`, including unique indexes.
- Seeder creates production and staging with generated `fl_sdk_*` / `fl_sig_*` keys
  (`KeyGenerator`); `app:create-environment {name}` adds more, printing keys once.
- `app:create-user` command; session login/logout with IP-throttled login; every dashboard
  route behind `auth`; no registration route.
- Flag CRUD: create (key, name, description, type, string variants), index with environment
  switcher and per-env status column, edit (name/description only; key and type immutable),
  archive with confirm. Boolean flags auto-create `true`/`false` variants.
- Per-environment state form on the flag edit screen: enabled toggle, off variant select,
  fallthrough variant select (rollouts arrive in Phase 3).
- Every mutation writes its audit row in the same transaction (`audit.recorded` logged);
  audit trail page with filters (flag, environment, action) and before/after display.
- Environments page: read-only list, keys revealed behind a no-JS disclosure.

### Verification checklist

- App runs (`php artisan serve`), `php artisan test` green, `./vendor/bin/pint --test` clean.
- Fresh `migrate --seed` yields production + staging with distinct keys; `app:create-user` then
  login works; wrong password gives a safe error; guarded routes redirect to `/login`.
- Creating a boolean flag shows exactly `true`/`false` variants; a string flag rejects fewer
  than 2 or duplicate variant values; a duplicate flag key is rejected with a field error; an
  invalid key (uppercase, `:`, leading `-`) is rejected.
- Toggling enabled and changing the off variant persist per environment and do not leak into
  the other environment; archive hides the flag from the index (with an "archived" filter to
  see it) and its state becomes read-only.
- Every mutation above produced exactly one audit row with correct actor, action, and
  before/after; the trail filters combine and paginate; empty states render.
- Unhappy paths: empty create form gives field errors, no 500; double-submit of create does
  not produce two flags (unique key holds); 255-char name accepted or cleanly rejected.

### Commits

1. `chore: scaffold laravel app with sqlite and database session cache`
2. `chore: add env example and flagline config`
3. `feat: add operator login and create user command`
4. `feat: add environments migration model and seeder`
5. `feat: add create environment command`
6. `feat: add flags and variants migrations and models`
7. `feat: add flag environment state migration and model`
8. `feat: add flag crud screens`
9. `feat: add per environment state form`
10. `feat: add audit log writes and trail page`
11. `feat: add environments page with key reveal`
12. `test: cover auth flag crud state and audit`

---

## Phase 2 - Atomic publishing and signed ruleset distribution

**Goal**: Every config change publishes a versioned, signed ruleset document atomically, and
SD-key-authenticated clients fetch it with ETag/304 revalidation. The kill switch ships here
because it is pure distribution: a bit in the document plus a one-click publish.

### Scope and tasks

- `rulesets` migration and model (immutable rows, unique `(environment_id, version)`).
- `RulesetBuilder`: config rows to the schema-v1 document array, variant indexes from
  `sort_order`, archived flags excluded, empty environment synthesized as version 0.
- `RulesetSigner`: HMAC-SHA256 sign/verify over exact bytes, constant-time compare.
- `RulesetPublisher`: the single transaction (mutate via callback, audit, build, insert with
  version = max+1, etag, signature); unique-index conflict retried once with fresh reads;
  `ruleset.published` / `ruleset.publish_conflict` logged. All Phase 1 mutations are rerouted
  through it (they gain publishing without behavior change).
- `GET /api/v1/ruleset`: `AuthenticateSdkKey` middleware (bearer to environment, 401 envelope,
  per-key rate limit), latest-row serve, stored ETag with If-None-Match 304, headers
  `ETag`, `X-Flagline-Signature`, `Cache-Control: private, no-cache`.
- Kill switch: kill/restore buttons (confirm on kill) on the flag index and edit screens;
  `flag.killed` / `flag.restored` audited and published synchronously.
- Scheduled prune keeps the last `FLAGLINE_RULESET_KEEP` versions per environment.

### Verification checklist

- Full suite green including new feature tests; Pint clean.
- `curl` with a valid key returns the document with `ETag` and `X-Flagline-Signature`;
  recomputing the HMAC over the exact body with the seeded secret matches the header; a second
  request with `If-None-Match` returns 304 with an empty body.
- Editing a flag bumps `version` by exactly 1 and changes the ETag; the served body reflects
  the change immediately after the redirect (same-transaction publish observed).
- Killing a flag publishes synchronously; the next fetch shows `killed: true`; restore
  reverses it; both actions appear in the audit trail with `ruleset_version` set.
- Wrong/missing bearer gives the 401 envelope; wrong method 405; hammering one key past the
  limit gives 429 while another key is unaffected.
- Concurrency: the publish-conflict test (two publishers racing the same version) shows one
  winner, one retried publish, both changes present in the final document, no gap in versions.
- A fresh environment (no publishes) serves the version-0 empty document with valid ETag and
  signature, and both are stable across requests.

### Commits

1. `feat: add rulesets migration and model`
2. `feat: add ruleset builder`
3. `feat: add ruleset signer`
4. `feat: add ruleset publisher transaction`
5. `feat: route existing mutations through the publisher`
6. `feat: add sdk key middleware and ruleset endpoint`
7. `feat: add etag revalidation with 304`
8. `feat: add kill switch with synchronous publish`
9. `feat: add ruleset retention prune`
10. `test: cover publishing distribution kill and conflicts`

---

## Phase 3 - Targeting rules and percentage rollouts

**Goal**: The operator can target by attributes and semver and roll out gradually with basis
point weights; the published document carries rules and rollouts exactly per the schema.

### Scope and tasks

- `rules` and `rule_clauses` migrations and models with the unique priority index.
- No-JS rule builder on the flag edit screen: add/edit/delete rules, one clause row per
  clause (attribute, operator select, values), add/remove clause round-trips, move rule
  up/down (priorities resequenced contiguously), rule description field.
- Serve editor per rule and for fallthrough: fixed variant select or rollout weight inputs
  (one per variant, basis points, must sum to 10000; server-validated).
- Shrink warning: saving a rollout that lowers any variant's weight shows the reshuffle
  warning from `docs/architecture.md` (flash notice, not a block).
- Validation: operator enum, non-empty values arrays, `eq`/`contains`/semver using a single
  value, semver clause values must parse as semver at save time (best-effort guard; the SDKs
  still treat unparseable as non-matching).
- All rule mutations audited and published through `RulesetPublisher`; document includes
  `rules` and rollout serves per the schema; builder resolves `variant_id` to index.

### Verification checklist

- Full suite green; Pint clean; rule builder passes a keyboard-only manual pass.
- Creating two rules and reordering them swaps their order in the served document; deleting
  the first resequences priorities with no gap; each step bumped the version once.
- Weights 3000/7000 accepted; 3000/6000 rejected with a field error; negative or over-10000
  rejected; a rollout on a single-variant selection rejected.
- A semver clause with value `1.2.x` is rejected at save; `1.2.3-beta.1` is accepted.
- Lowering a weight shows the shrink warning; raising it does not.
- Unhappy paths: deleting a rule twice (stale second tab) fails gracefully with a flash, not a
  500; a forged operator value outside the enum is rejected; clause value of 1000 chars is
  cleanly rejected.

### Commits

1. `feat: add rules and clauses migrations and models`
2. `feat: add rule builder screens`
3. `feat: add clause editing with operator validation`
4. `feat: add rule reordering with contiguous priorities`
5. `feat: add rollout serve editor with weight validation`
6. `feat: add rollout shrink warning`
7. `feat: publish rules and rollouts in the document`
8. `test: cover rule building validation and publishing`

---

## Phase 4 - Test vectors and the PHP SDK

**Goal**: The evaluation spec becomes executable: `sdks/test-vectors/` is authored as the
cross-SDK contract, and the PHP SDK passes it end to end with polling, verification, and local
evaluation.

### Scope and tasks

- `sdks/test-vectors/bucketing.json`: at least 50 entries `{flag_key, user_key, hash, bucket}`
  including the three reference values from `docs/architecture.md`, empty user key edge cases,
  unicode keys, and long keys.
- `sdks/test-vectors/evaluation.json`: at least 40 named cases, each a full document, context,
  default, expected value, and expected reason; covering every reason (`not_ready` excepted,
  SDK-level), every operator including semver prerelease ordering, missing attributes, rollout
  boundaries (bucket exactly at a cumulative edge), `no_user_key`, killed, off, flag_not_found,
  and a before/after weight-raise pair proving the monotonic invariant.
- `sdks/php` composer package `flagline/flagline-php` (PHP 8.1+, no runtime deps):
  `Client` (constructor options, `start`/`stop`, `variation`, `boolVariation`, error callback),
  `Fetcher` (ETag polling with jitter, timeout, degrade rules), `Signature` (verify),
  `Bucketer` (`hash('murmur3a')` + hexdec), `Semver`, `Evaluator` (the spec).
- Pest suite consuming both vector files plus fetcher tests against a local stub server
  (200/304/401/timeout/bad signature/bad schema_version).
- App-side: a feature test asserts a freshly published document round-trips through the PHP
  SDK evaluator (integration proof that builder output matches evaluator input).

### Verification checklist

- `cd sdks/php && ./vendor/bin/pest` green; every bucketing and evaluation vector passes.
- Vector spot-check against the app: publish a flag with a 2000/8000 rollout, fetch with the
  SDK, and confirm `user-42` on `checkout-redesign` gets the first variant (bucket 78).
- Kill a flag while the SDK polls: within poll interval + jitter + timeout the SDK serves the
  off variant (tested with a fake clock, observed once live).
- Stop the server: `variation` keeps returning last-good values; error callback fired with
  `network`; restart resumes polling without intervention.
- Tampered body (flip one byte in a proxy stub): SDK rejects, keeps last good, callback fires
  with `signature_mismatch`; same for an unknown `schema_version`.
- `variation` before `start` or before the first fetch returns the default with `not_ready`;
  no exception escapes the SDK in any covered scenario.

### Commits

1. `feat: add bucketing test vectors`
2. `feat: add evaluation test vectors`
3. `feat: scaffold php sdk package`
4. `feat: add php bucketer and semver`
5. `feat: add php evaluator`
6. `feat: add php signature verification`
7. `feat: add php fetcher with etag polling`
8. `feat: add php client api`
9. `test: cover php sdk with vectors and stub server`
10. `test: round trip published document through php sdk`

---

## Phase 5 - TypeScript SDK and cross-SDK parity

**Goal**: The TypeScript SDK reaches behavioral identity with the PHP SDK, proven by the same
vectors, and the repository is release-ready (README, SDK docs, hardening).

### Scope and tasks

- `sdks/typescript` npm package `flagline-node` (Node 18+, zero runtime deps, `tsc --strict`):
  `client.ts`, `fetcher.ts` (native `fetch`, AbortController timeout, ETag, jitter),
  `signature.ts` (`createHmac` + `timingSafeEqual`), `bucketer.ts` (inline murmur3_32),
  `semver.ts`, `evaluator.ts`; ESM + CJS builds.
- Vitest suite consuming the same `sdks/test-vectors/` files plus fetcher tests against a stub
  server; murmur3 additionally checked against published reference vectors for the algorithm.
- Parity gate: a script runs both SDK suites and diffs their vector results; any divergence is
  a Phase 5 blocker, fixed in the SDK that violates the spec (vectors change only with owner
  sign-off).
- README finalized: real install/run/test instructions, SDK quickstarts for both languages,
  kill switch propagation bound documented for consumers.
- Hardening pass: rate limit on login re-verified, API 404/405 envelopes confirmed, dashboard
  empty states and 320px width pass per `docs/design.md`, `docs/testing.md` commands verified
  as written.

### Verification checklist

- `npm test` and `tsc --strict` green in `sdks/typescript`; both builds (ESM/CJS) import
  cleanly in a scratch Node project.
- Both SDK suites pass the identical vector files; the parity script reports zero divergence.
- Live cross-check: one running server, both SDKs polling the same environment; for 20
  scripted contexts both return identical variants; a kill propagates to both within the
  bound.
- Full app suite, Pint, and both SDK suites green from a clean clone by following only the
  README commands.
- Unhappy paths re-run from Phase 4's checklist against the TS SDK (network down, tamper,
  schema version, not ready): identical degrade behavior.

### Commits

1. `feat: scaffold typescript sdk package`
2. `feat: add typescript murmur3 bucketer`
3. `feat: add typescript semver and evaluator`
4. `feat: add typescript signature verification`
5. `feat: add typescript fetcher with etag polling`
6. `feat: add typescript client api`
7. `test: cover typescript sdk with vectors and stub server`
8. `feat: add cross sdk parity script`
9. `docs: finalize readme with sdk quickstarts`
10. `test: harden api envelopes and dashboard states`

---

## Backlog

- SDK bootstrap-from-file (serve last good ruleset across restarts) - useful for serverless
  PHP where in-memory caching resets per request; needs a file locking story first.
- SDK key and signing secret rotation command with overlap window - security hygiene; deferred
  because rotation without dual-key support breaks polling SDKs mid-rotation.
- Flag prerequisites (flag A requires flag B) - explicit PRD non-goal for v1; revisit only
  with a concrete use case.
- Per-user override pinning UI - PRD non-goal; targeting on the `key` attribute already covers
  the workaround (`key in [user-1, user-2]`).
- Evaluation telemetry (which flags are actually queried) - would need an ingest path and
  retention story; conflicts with the no-queue design as stated.
- Dashboard JSON API for automation/IaC - v1 is UI plus artisan only; scope only with a real
  consumer.
