# flagline

A self-hosted feature flag and gradual rollout service built with Laravel, plus two thin
local-evaluation SDKs (PHP and TypeScript) shipped in the same repository. Define boolean or
multivariate flags per environment, target users with attribute and semver rules, roll variants
out gradually with deterministic percentage bucketing, and kill a misbehaving flag with one
click. SDKs poll a signed, versioned ruleset document with ETag revalidation and evaluate every
flag locally, so flag checks cost zero network calls and keep working from the last good
ruleset even when the flag server is down.

## The problem it solves

Teams that want feature flags face a choice between hosted services (per-seat pricing, your
targeting data on someone else's infrastructure) and wiring config values by hand (no gradual
rollout, no targeting, no audit trail, redeploys to toggle). flagline is the self-hosted middle
path: real rollout mechanics on infrastructure you run, with a deliberately small footprint,
PHP plus a database, no Redis, no queue, no Node toolchain in the app.

## Planned features

All of the following is planned; nothing is implemented yet. Behavior described below is the
target defined by the docs.

- Flags with variants: boolean (`true`/`false`) or string multivariate (2 to 20 values).
- Environments (production and staging seeded; more via artisan), each with its own SDK key
  and signing secret; fully independent flag state per environment.
- Targeting rules: ordered, first match wins; clauses on context attributes with `eq`, `in`,
  `contains`, and semver comparisons (`semver_eq`, `semver_gt`, `semver_gte`, `semver_lt`,
  `semver_lte`).
- Deterministic percentage rollouts: bucket = murmur3_32(`flag_key:user_key`) mod 10000, so
  the same user always gets the same variant in both SDKs, and increasing a rollout never
  reshuffles already-assigned users.
- Instant kill switch per flag per environment, published atomically; worst-case SDK
  propagation is the poll interval plus jitter plus one request timeout (36s at defaults).
- Signed, versioned ruleset distribution: one document per environment at
  `GET /api/v1/ruleset` with a strong ETag and `If-None-Match` 304s, plus an HMAC-SHA256
  signature header so SDKs reject tampered documents.
- Audit log of every change: who, what, before/after snapshots, and the ruleset version each
  change published; browsable and filterable in the dashboard.
- Server-rendered Blade dashboard: flag list with environment switcher, a no-JavaScript rule
  builder, kill/restore controls, and the audit trail.
- Two SDKs in `sdks/`: PHP (composer, `flagline/flagline-php`) and TypeScript (npm,
  `flagline-node`), both evaluating locally from the fetched document and both proven
  identical by a shared test-vectors file.

## Stack

- PHP 8.2+ / Laravel 11.x, Blade plus one static CSS file (no Node toolchain in the app)
- SQLite by default, PostgreSQL supported; no Redis, no queue daemon
- Pest (app and PHP SDK tests), Vitest + tsc (TypeScript SDK tests), Laravel Pint
- SDKs: PHP 8.1+ with zero runtime dependencies; TypeScript targeting Node 18+ with zero
  runtime npm dependencies

## Documentation

| Document | Contents |
|---|---|
| [docs/PRD.md](docs/PRD.md) | Problem, target user, prioritized features, non-goals, success criteria |
| [docs/architecture.md](docs/architecture.md) | Stack rationale, evaluation spec, bucketing, document schema, signing, data model, failure modes, invariants |
| [docs/rules.md](docs/rules.md) | Project-specific engineering rules extending the workspace rules |
| [docs/phases.md](docs/phases.md) | Five implementation phases with commit lists and verification checklists |
| [docs/design.md](docs/design.md) | Dashboard screens, states, tokens, accessibility baseline |
| [docs/testing.md](docs/testing.md) | Test strategy across the three suites, shared vectors, CI plan |
| [docs/api-contracts.md](docs/api-contracts.md) | Ruleset endpoint, error envelope, SDK public APIs, dashboard routes |
| [docs/launch-checklist.md](docs/launch-checklist.md) | Pre-production checks including kill switch and server-death drills |
| [docs/memory.md](docs/memory.md) | Working log and decisions record |

## Status

Planning stage: these documents are the complete specification and no code exists yet.
Implementation follows `docs/phases.md` one phase at a time, each phase gated by its
verification checklist and owner approval.
