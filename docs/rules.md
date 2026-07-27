# Engineering Rules - flagline

These rules are binding for every change in this repository. They extend the workspace-level
rules in the parent `CLAUDE.md`; where both speak, the stricter one wins.

## Conventions

- **Framework patterns**: Laravel idioms throughout. Controllers stay thin; validation lives in
  Form Requests; every config mutation goes through `RulesetPublisher` (the only place the
  publish transaction is opened); document construction lives in `RulesetBuilder`; signing in
  `RulesetSigner`. No query logic in routes, middleware, or Blade.
- **The evaluation spec is law**: The algorithm in `docs/architecture.md` plus
  `sdks/test-vectors/` define evaluation. Neither SDK may deviate, "improve", or extend it
  independently; a behavior change starts as a vectors change, flagged to the owner, then lands
  in both SDKs in the same phase. The two SDKs must stay behaviorally identical at all times.
- **SDK isolation**: `sdks/php` and `sdks/typescript` are standalone packages. They must not
  import from the Laravel app or from each other; their only shared artifact is
  `sdks/test-vectors/`. Each has its own lockfile, test suite, and README section. The TS SDK
  has zero runtime npm dependencies; the PHP SDK requires only PHP 8.1+ and ext-hash.
- **Preferred libraries**: Only what the stack already includes: Eloquent, Pest, Pint, Vitest,
  tsc, `node:crypto`. Hashing and signing use language built-ins (`hash('murmur3a')`,
  `hash_hmac`/`hash_equals`, `createHmac`/`timingSafeEqual`). Do not add a semver library, a
  murmur3 package, an HTTP client wrapper, DTO libraries, or an admin panel.
- **What to avoid**: No raw SQL with user input; no driver-specific SQL (must run on SQLite and
  PostgreSQL; JSON columns are text + array casts, date math in PHP); no logic in Blade beyond
  display and loops; no JavaScript in the dashboard unless a phase explicitly calls for it; no
  `env()` calls outside `config/`.
- **Naming (PSR-12 + Laravel)**: Controllers `PascalCaseController`; models singular
  `PascalCase`; Form Requests `VerbNounRequest`; tables plural snake_case; columns snake_case;
  routes lowercase. Flag keys are validated against `^[a-z0-9][a-z0-9_-]{0,99}$` and are
  immutable after creation. Pint enforces PHP style; `tsc --strict` plus Vitest gate the TS SDK.
- **Commit format**: Conventional Commits, short imperative subject, e.g.
  `feat: add ruleset publisher transaction`, `fix: reject rollout weights not summing to 10000`.
- **ONE COMMIT PER FEATURE**: Each feature or task is exactly one commit; never batch features,
  never fragment one small feature. The commit lists in `docs/phases.md` are the intended order.
- **Pin exact dependency versions**: Exact versions in `composer.json` and `package.json`;
  lockfiles committed. Any dependency change is its own commit and needs approval first.
- **DB migration rule**: Every schema change goes through a migration file. Never edit an
  applied migration; add a new one. Model `$fillable`/`$casts` changes ship in the same commit
  as the migration introducing the columns.
- **Immutability rules**: `rulesets` and `audit_logs` rows are insert-only; no code path
  updates or deletes them (except the documented ruleset prune). Variant `sort_order` and flag
  `key` never change after creation; the document's variant indexes depend on it.

## Error handling & logging

- **Every fallible call handles failure**: The publish transaction (version conflict retried
  once, then surfaced), database access, and both SDKs' fetch path (timeout, connection error,
  non-200, bad signature, bad schema version) all handle failure explicitly. `variation()` in
  either SDK must never throw on any input; the worst outcome is the caller's default.
- **The API never 500s on bad input**: Missing/unknown bearer token, wrong method, and
  malformed headers each map to their documented status and envelope. A fresh environment with
  no publishes serves the synthesized version-0 document, not an error.
- **Friendly user errors vs detailed logs**: The dashboard gets flash messages and field
  errors; the API gets the short envelope. Full context (exception class, flag id, environment,
  version, never secrets) goes to logs only. `APP_DEBUG=false` outside local; no stack traces
  in any response.
- **One consistent JSON error format** (see `docs/api-contracts.md`):
  `{ "error": { "code": "...", "message": "..." } }` for every API error, no exceptions.
- **Structured logging from day one**: Context arrays with dotted event keys:
  `ruleset.published`, `ruleset.publish_conflict`, `ruleset.served`, `ruleset.not_modified`,
  `sdk.auth_failed`, `flag.killed`, `flag.restored`, `audit.recorded`, `auth.login_failed`,
  `prune.completed`. Example:
  `Log::info('ruleset.published', ['environment' => 'production', 'version' => 42])`.
- **SDK-side errors**: SDKs log nothing themselves; they invoke the caller-supplied error
  callback with a typed reason (`network`, `http_status`, `signature_mismatch`,
  `schema_version`) and otherwise stay silent. Library code does not own the host app's logs.

## Security

- **No hardcoded secrets**: Config secrets in `.env` (git-ignored); `.env.example` carries
  dummies. Environment signing secrets live in the database encrypted via the `encrypted` cast
  and are never logged. The environments page reveals keys behind a no-JS disclosure, operator
  session required.
- **API auth**: `GET /api/v1/ruleset` requires `Authorization: Bearer <sdk_key>`; unknown or
  missing keys get 401 with the envelope and a log line (key prefix only, never the full key).
  The endpoint is read-only and rate-limited per key; there are no API mutation routes.
- **Signature discipline**: Signatures and verifications are constant-time (`hash_equals` /
  `timingSafeEqual`). SDKs verify before parsing JSON; an unverified body is never evaluated.
- **Dashboard auth**: Session login on every dashboard route; login throttled by IP; logout
  invalidates and regenerates the session; no registration route; CSRF on all dashboard POSTs.
  The stateless API routes live outside session and CSRF middleware.
- **Validate all input server-side** via Form Requests: flag keys and types, variant values,
  rollout weights (integers 0 to 10000 summing to exactly 10000), operators against the enum,
  clause values (non-empty string arrays, bounded lengths), rule priorities. Never trust the
  client.
- **Rendering user data**: Flag names, descriptions, clause values, and audit snapshots render
  through Blade escaping (`{{ }}`), never `{!! !!}`.
- **Queries**: Eloquent/parameter binding only.

## Simplicity / YAGNI-KISS

- Build only what the current phase requires. No speculative operators, no streaming, no
  webhooks, no config toggles beyond the documented env values.
- No abstraction until three real use cases exist. The publisher/builder/signer split is
  justified by the atomicity invariant; nothing else warrants a service class in v1.
- No new wrapper classes, factories, managers, or utils files without owner approval first.
- Before submitting, self-review: can this be done in fewer lines without hurting readability?
  If a solution exceeds ~150 lines, pause and justify it.

## Boundaries - never do without asking the owner first

- **No wholesale delete/rewrite** of working files. Targeted edits; flag destructive changes.
- **Do not change `docs/PRD.md` or `docs/architecture.md`** without flagging the change and its
  reason and getting sign-off; they are the source of truth. The test vectors carry the same
  weight once authored.
- **No new dependency without approval.** Propose what, why, version, and size, then wait.
- **Ask when ambiguous** rather than guessing at product behavior.
- **Stop after two failed fix attempts** on the same problem; report what was tried instead of
  thrashing.
- **Scope discipline**: any mid-phase request not in `docs/PRD.md` gets classified with the
  owner as current phase, new phase, or Backlog in `docs/phases.md`. Never silently absorb
  scope.
