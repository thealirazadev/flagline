# Architecture - flagline

## System flow

```
Operator (browser, session-authenticated Blade dashboard)
        │  mutation: flag edit, rule change, kill, restore, ...
        ▼
ONE database transaction (RulesetPublisher):
        mutate config rows (flags, flag_environments, rules, clauses)
        → write one immutable audit_logs row (actor, action, before, after)
        → rebuild the environment's ruleset document from config rows
        → insert rulesets row: version = max(version)+1, body, etag, signature
   commit: the new document is instantly the served one
                ▼
GET /api/v1/ruleset   (Authorization: Bearer <sdk_key>; stateless, no session/CSRF)
        ├─ resolve environment by sdk_key        → unknown: 401, JSON envelope
        ├─ load latest rulesets row (indexed)    → none yet: empty document, version 0
        ├─ If-None-Match matches stored etag     → 304, empty body (the cheap path)
        └─ 200: stored body verbatim + ETag + X-Flagline-Signature headers
                ▼
SDKs (sdks/php, sdks/typescript) - identical behavior by spec
        ├─ poll every pollSeconds (default 30) with If-None-Match
        ├─ verify signature over exact body bytes (constant-time); mismatch
        │     or unknown schema_version: discard, keep last good ruleset
        └─ variation(flag_key, context, default): pure local evaluation
```

## Evaluation spec (normative)

Both SDKs implement exactly this algorithm; `sdks/test-vectors/` is its executable form, and
when prose and vectors disagree, the vectors win and the prose gets fixed. A **context** is
`{ key: string, attributes: map<string, string> }`. For `variation(flag_key, context, default)`:

1. No ruleset fetched yet → return `default`, reason `not_ready`; `flag_key` absent from the
   document → return `default`, reason `flag_not_found`.
2. `killed` is true → return the off variant, reason `killed`.
3. `enabled` is false → return the off variant, reason `off`.
4. Walk `rules` in array order. The first rule whose clauses ALL match wins (short-circuit:
   later rules are not evaluated). Resolve its `serve` (below), reason `rule_match` with the
   rule index. Clause matching:
   - The attribute value comes from `context.attributes[clause.attribute]`; the special name
     `key` reads `context.key`. A missing attribute makes the clause false, never an error.
   - `eq`: exact string equality with `values[0]`. `in`: membership in `values` (exact match).
     `contains`: `values[0]` is a substring of the attribute value. All case-sensitive.
   - `semver_eq|gt|gte|lt|lte`: both sides must parse as `major.minor.patch` with an optional
     `-prerelease` suffix (build metadata after `+` ignored); if either fails to parse the
     clause is false. Precedence follows semver 2.0.0: numeric fields numerically; a
     prerelease sorts before the same version without one; prerelease identifiers compare
     dot-segment by dot-segment, numeric before alphanumeric, numeric segments numerically.
5. No rule matched → resolve the `fallthrough` serve, reason `fallthrough`.

Resolving a **serve**:

- `{ "variant": i }` → the variant at index `i` of the flag's `variants` array.
- `{ "rollout": [ { "variant": i, "weight": w }, ... ] }` → requires `context.key`; if empty,
  return the off variant, reason `no_user_key`. Otherwise compute the bucket (below) and walk
  the entries in array order, accumulating weights; the first entry where
  `bucket < cumulative_weight` wins. Weights are basis points summing to exactly 10000.

**Bucketing (normative).** `bucket = murmur3_32(utf8("{flag_key}:{user_key}"), seed = 0) mod
10000`, where `murmur3_32` is canonical MurmurHash3_x86_32 taken as an unsigned 32-bit
integer. Flag keys match `^[a-z0-9][a-z0-9_-]{0,99}$` (enforced at creation), so they can
never contain `:` and the concatenation is unambiguous. PHP uses the built-in
`hash('murmur3a', $input)` (PHP 8.1+) with the hex result converted via `hexdec`; TypeScript
ships a dependency-free inline implementation. Reference values, verified against the
algorithm's published reference vectors and repeated in the vectors file:

| Input | Hash (unsigned) | Bucket |
|---|---|---|
| `checkout-redesign:user-42` | 111560078 | 78 |
| `checkout-redesign:user-43` | 2215518393 | 8393 |
| `new-pricing:user-42` | 2161741713 | 1713 |

Including the flag key decorrelates flags: `user-42` lands in bucket 78 for
`checkout-redesign` but 1713 for `new-pricing`, so 10% rollouts on two flags hit different users.

**Monotonic rollout invariant.** Variant order inside a rollout is the flag's variant order
and is never reorderable in the UI. Variant at index `i` owns the bucket range
`[cum(i-1), cum(i))`. If the weights of all variants before `i` are unchanged and `w_i`
increases, the old range is a strict subset of the new range: every user already assigned to
that variant keeps it, which is why "raise 20% to 30%" never reshuffles. The guarantee does
not hold if an earlier variant's weight changes at the same time; the dashboard warns when a
save shrinks any weight.

## Ruleset document (schema version 1)

```json
{ "schema_version": 1, "environment": "production", "version": 42,
  "published_at": "2026-07-27T12:00:00Z",
  "flags": {
    "checkout-redesign": {
      "type": "boolean", "enabled": true, "killed": false,
      "variants": ["true", "false"], "off_variant": 1,
      "rules": [
        { "clauses": [ { "attribute": "plan", "op": "in", "values": ["pro", "enterprise"] } ],
          "serve": { "variant": 0 } }
      ],
      "fallthrough": { "rollout": [ { "variant": 0, "weight": 2000 },
                                    { "variant": 1, "weight": 8000 } ] } } } }
```

- Variants are referenced by index into `variants` (ordered by `variants.sort_order`); values
  are always strings (`"true"`/`"false"` for boolean flags; SDK `boolVariation` converts).
- `version` is a per-environment monotonic counter; `schema_version` describes the document
  shape. SDKs hard-code the schema versions they understand (initially only 1) and treat any
  other value like a bad signature: discard, keep last good, error callback. Additive changes
  do not bump `schema_version` (SDKs must ignore unknown fields); semantics changes do.
- Archived flags are simply absent. An environment with no published change yet serves the
  synthesized empty document (`"version": 0, "flags": {}`) with a valid ETag and signature.

**Signing.** The response header `X-Flagline-Signature: sha256=<lowercase hex
hmac_sha256(signing_secret, body)>` covers the exact stored body bytes, computed once at
publish time. Serving never re-serializes JSON, so there is no canonicalization problem: the
bytes signed are the bytes served. SDKs verify with a constant-time compare before parsing.
The SDK key (bearer auth, appears in request logs) and the signing secret (never
transmitted) are deliberately separate credentials.

**Caching contract.** Responses carry `Cache-Control: private, no-cache`: caches may store
but must revalidate, so every poll hits the app and the 304 path is the economizer.
Worst-case change propagation is `pollSeconds + jitter + timeout` (36s at defaults); a shared
cache adding `s-maxage` adds that many seconds to the kill bound (see the launch checklist).

## Publish pipeline invariants

- **Atomicity.** Config mutation, audit row, and ruleset insert commit together or not at all.
  The API only ever sees fully published versions; there is no partial state.
- **Version monotonicity.** `version = 1 + max(version)` is computed inside the transaction
  and guarded by the unique `(environment_id, version)` index. Concurrent saves cannot both
  claim a version: the loser hits the unique index and the publish is retried once in a fresh
  transaction, re-reading config so the retry publishes the merged truth. SQLite serializes
  writers; the retry exists for PostgreSQL.
- **Idempotent distribution.** Rulesets rows are immutable after insert; body, etag, and
  signature never change for a given version, so responses are reproducible.
- **Audit completeness.** Every mutation goes through `RulesetPublisher`, the only place this
  transaction is opened, so no code path changes config without an audit row.
- **Retention.** Only the latest row is served; the last `FLAGLINE_RULESET_KEEP` per
  environment are kept for debugging, older ones pruned on a schedule. Audit rows: never.

## Failure modes

| Failure | Handling |
|---|---|
| Two operators save the same flag concurrently | Last commit wins on config rows; the version unique index plus one retry (above) keeps versions contiguous; both saves are audited. |
| Publish transaction fails mid-way | Full rollback: no config change, no audit row, no version. Friendly error to the operator; details logged. |
| SDK poll: network error, timeout, 5xx, or 401 | Keep last good ruleset, schedule next poll, invoke the error callback (`network` or `http_status`). Never throw from `variation()`. |
| SDK poll: signature mismatch or unknown `schema_version` | Discard body, keep last good ruleset, error callback (`signature_mismatch` / `schema_version`). Protects against tampering proxies, misconfigured secrets, and old SDKs misreading new semantics. |
| SDK never reached the server | Every evaluation returns the caller default with reason `not_ready`; the app ships with safe defaults by construction. |
| Rollout evaluated with empty `context.key` | Off variant, reason `no_user_key`: bucketing is impossible without a key and the off variant is the conservative choice. |
| Missing attribute or unparseable semver in a clause | Clause is false; evaluation continues. A bad `app_version` upstream must not crash flagging. |
| SQLite `database is locked` under write contention | `busy_timeout` set; the publish retry covers the residual failure. |
| Operator kills a flag while SDKs are mid-poll | The in-flight response may carry the old version; the next poll gets the kill within the documented bound. |

## Proposed folder / file tree

```
app/
├── Console/Commands/
│   ├── CreateOperatorCommand.php        # app:create-user {email} (prompts for password)
│   └── CreateEnvironmentCommand.php     # app:create-environment {name} (prints keys once)
├── Http/
│   ├── Controllers/                     # Api/RulesetController (bearer, ETag/304),
│   │                                    # Auth/LoginController, FlagController,
│   │                                    # FlagEnvironmentController (per-env state),
│   │                                    # KillSwitchController, RuleController,
│   │                                    # EnvironmentController, AuditLogController
│   ├── Requests/                        # Login, StoreFlag, UpdateFlag,
│   │                                    # UpdateFlagEnvironment, StoreRule, UpdateRule
│   └── Middleware/AuthenticateSdkKey.php# resolves Environment from the bearer token
├── Models/                              # User, Environment, Flag, Variant,
│                                        # FlagEnvironment, Rule, RuleClause,
│                                        # Ruleset, AuditLog
└── Support/
    ├── RulesetPublisher.php             # THE transaction: mutate, audit, build, insert
    ├── RulesetBuilder.php               # config rows to the schema-v1 document array
    ├── RulesetSigner.php                # HMAC-SHA256 sign/verify over exact bytes
    └── KeyGenerator.php                 # fl_sdk_* / fl_sig_* random tokens

config/flagline.php                      # retention, publish retry (env-backed)
database/                                # migrations (framework tables, then one per table
                                         # below in dependency order), factories, seeder
public/css/app.css                       # the only stylesheet; no Node build
resources/views/                         # layouts/app, auth/login, flags/{index,create,
                                         # edit} (edit hosts the rule builder),
                                         # environments/index, audit/index
routes/
├── web.php                              # login + dashboard (auth middleware)
└── api.php                              # GET /api/v1/ruleset (AuthenticateSdkKey only)

sdks/
├── test-vectors/
│   ├── bucketing.json                   # [{flag_key, user_key, hash, bucket}]
│   └── evaluation.json                  # [{name, document, context, flag_key, default,
│                                        #   expected_value, expected_reason}]
├── php/                                 # composer package flagline/flagline-php:
│   │                                    # composer.json, tests/ (Pest, ../test-vectors)
│   └── src/{Client,Fetcher,Evaluator,Bucketer,Semver,Signature}.php
└── typescript/                          # npm package flagline-node:
    │                                    # package.json, tests/ (Vitest, ../test-vectors)
    └── src/{client,fetcher,evaluator,bucketer,semver,signature}.ts

tests/
├── Feature/                             # Auth, FlagCrud, FlagEnvironment, Rules,
│                                        # KillSwitch, RulesetApi, AuditTrail,
│                                        # PublishConcurrency
└── Unit/                                # RulesetBuilder, RulesetSigner, KeyGenerator
```

## Tech stack with rationale

- **Laravel 11.x (PHP 8.2+)** - Routing, validation, Eloquent, transactions, scheduler,
  first-class testing. Exact versions pinned at install; `composer.lock` committed. PHP 8.1+
  gives native `hash('murmur3a')`, so server and PHP SDK need no hashing dependency.
- **SQLite (default) / PostgreSQL (supported)** - SQLite makes self-hosting a one-file affair
  and backs in-memory tests; PostgreSQL for teams that already run it. Consequence: no
  driver-specific SQL; JSON columns are `text` with `array` casts; concurrency assumes the
  weaker engine (SQLite single writer) and adds the retry the stronger one needs.
- **No Redis, no queue** - Nothing is asynchronous: publishes are synchronous transactions and
  distribution is HTTP revalidation. A 304 is one indexed SELECT; hundreds of polling SDK
  instances cost a few queries per second. Losing the cache daemon is a feature for
  self-hosters; the ETag design is what makes it possible.
- **Blade + one static CSS file** - Server-rendered dashboard, plain form POSTs, no Node build
  for the app; the rule builder works via round-trip forms (see `docs/design.md`).
- **Pest on PHPUnit** (app and PHP SDK), **Vitest + tsc** (TypeScript SDK), **Laravel Pint**.
  Each SDK is an independent package with its own lockfile; the shared vectors are plain JSON.

No other runtime dependencies. Signing is `hash_hmac` + `hash_equals` (PHP) and `node:crypto`
`createHmac` + `timingSafeEqual` (TS). The TS SDK targets Node 18+ with zero runtime npm
dependencies; murmur3 and semver are small inline implementations proven by the vectors.

## Data model

Tables named here are the contract; the coding agent must not rename them. All tables carry
Laravel timestamps unless noted. `users` is the standard operator account table (id, name,
unique email, hashed password); there is no registration.

### environments
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | string, unique | `production`, `staging`, ... lowercase slug, immutable |
| sdk_key | string(64), unique, indexed | `fl_sdk_` + 32 random URL-safe chars; bearer credential |
| signing_secret | text | encrypted cast; `fl_sig_` + 40 random chars; signs rulesets |

### flags
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| key | string(100), unique | `^[a-z0-9][a-z0-9_-]{0,99}$`; immutable after create (SDKs reference it) |
| name | string | display name |
| description | text, nullable | |
| type | string enum | `boolean` \| `string` |
| archived_at | datetime, nullable | archived flags drop out of new rulesets; history kept |

### variants
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| flag_id | bigint FK → flags.id | indexed |
| value | string(255) | `"true"`/`"false"` for boolean flags (auto-created) |
| sort_order | unsigned int | immutable; defines the document's `variants` index order |

Indexes: unique `(flag_id, value)`, unique `(flag_id, sort_order)`. Variants are append-only
for string flags (an index referenced by history can never be reassigned); deleting a variant
is out of scope for v1.

### flag_environments
One row per (flag, environment), created with the flag.
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| flag_id | bigint FK → flags.id | |
| environment_id | bigint FK → environments.id | |
| enabled | boolean, default false | new flags start off everywhere |
| killed | boolean, default false | kill switch; wins over everything |
| off_variant_id | bigint FK → variants.id | served when killed/off/no_user_key |
| fallthrough_variant_id | bigint FK → variants.id, nullable | fixed fallthrough serve |
| fallthrough_rollout | json (text), nullable | `[{variant_id, weight}]`, basis points |

Indexes: unique `(flag_id, environment_id)`. Exactly one of `fallthrough_variant_id` /
`fallthrough_rollout` is non-null (validated in the Form Request and the publisher).

### rules
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| flag_environment_id | bigint FK → flag_environments.id | indexed, cascade on delete |
| priority | unsigned int | evaluation order, contiguous from 0; resequenced on delete/reorder |
| description | string, nullable | operator note shown in the UI |
| serve_variant_id | bigint FK → variants.id, nullable | exactly one of the two serves non-null |
| serve_rollout | json (text), nullable | `[{variant_id, weight}]`, basis points |

Indexes: unique `(flag_environment_id, priority)`.

### rule_clauses
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| rule_id | bigint FK → rules.id | indexed, cascade on delete |
| attribute | string(100) | context attribute name; `key` targets the user key |
| operator | string enum | `eq` \| `in` \| `contains` \| `semver_eq` \| `semver_gt` \| `semver_gte` \| `semver_lt` \| `semver_lte` |
| values | json (text) | non-empty array of strings; `eq`/`contains`/semver use `values[0]` |

### rulesets
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| environment_id | bigint FK → environments.id | |
| version | unsigned int | monotonic per environment, starts at 1 |
| schema_version | unsigned int | 1 for now; stored so old rows stay self-describing |
| body | longText | the exact JSON bytes served; never re-serialized |
| etag | string(64) | `"{version}-{first 16 hex of sha256(body)}"`, stored quoted |
| signature | string(80) | `sha256=<hex hmac>`; computed at publish with the env secret |
| published_by | bigint FK → users.id, nullable | null for seeded/system publishes |
| created_at | datetime | immutable rows; no updated_at |

Indexes: unique `(environment_id, version)`; the serve query is
`WHERE environment_id = ? ORDER BY version DESC LIMIT 1` on that same index.

### audit_logs
| Field | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | bigint FK → users.id | actor |
| flag_id | bigint FK → flags.id, nullable | indexed; null for environment-level actions |
| environment_id | bigint FK → environments.id, nullable | indexed |
| action | string(50) | dotted key: `flag.created`, `flag.updated`, `flag.archived`, `flag.killed`, `flag.restored`, `rule.created`, `rule.updated`, `rule.deleted`, `environment.state_changed` |
| before | json (text), nullable | snapshot before the change; null on create |
| after | json (text), nullable | snapshot after; null on archive |
| ruleset_version | unsigned int, nullable | the version this change published |
| created_at | datetime | immutable rows; no updated_at |

Framework tables: `sessions`, `cache` (database drivers; no queue tables, nothing is queued).

## Where state lives

- **Database (single source of truth)** - environments, flags, variants, per-env state, rules,
  clauses, published rulesets, audit trail, sessions. One backup covers everything.
- **SDK memory** - the last good ruleset document plus its ETag. Nothing on disk; a restarted
  SDK is `not_ready` until its first successful fetch (bootstrap files are backlog).
- **Secrets/config** - `.env` only, except signing secrets, stored encrypted at rest via the
  `encrypted` cast (`APP_KEY`); SDK keys are plaintext so the indexed bearer lookup works.

## External dependencies and required env vars

External runtime services: none. Production needs PHP-FPM (or equivalent) and optionally
PostgreSQL; there is no worker, and the ruleset prune is the only scheduled task.

| Variable | Purpose |
|---|---|
| `APP_KEY` | Encryption key; also encrypts stored signing secrets. Must be generated. |
| `APP_URL` | Base host; the dashboard shows SDK base URLs built from it. |
| `APP_DEBUG` | Must be false outside local. |
| `DB_CONNECTION` / `DB_*` | `sqlite` (default) or `pgsql` + credentials. |
| `SESSION_DRIVER` / `CACHE_STORE` | `database`. |
| `FLAGLINE_RULESET_KEEP` | Published versions retained per environment (default 50). |

Config is read once in `config/flagline.php`; code reads config, never `env()` directly. SDK
configuration (base URL, SDK key, signing secret, poll seconds, timeout) lives in the consuming
application, passed to the SDK constructor; see `docs/api-contracts.md`.
