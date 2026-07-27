# Product Requirements - flagline

## What we're building

A self-hosted feature flag and gradual rollout service. An operator defines flags (boolean or
string multivariate), configures them per environment (production, staging), targets users with
attribute rules and semver comparisons, and rolls variants out gradually with deterministic
percentage bucketing, so the same user always sees the same variant and increasing a rollout never
reshuffles already-assigned users. Every flag change is audited (who, what, before/after) and
published as a signed, versioned ruleset document per environment. Two thin SDKs shipped in the
same repository, PHP (composer) and TypeScript (npm), poll that document cheaply via ETag and
If-None-Match and evaluate flags locally with zero per-evaluation network calls. A server-rendered
Blade dashboard provides the flag list, a rule builder, an instant kill switch, the audit trail,
and an environment switcher.

## Target user

A developer or small team running their own infrastructure who wants LaunchDarkly-style flagging
without a hosted service or a per-seat bill: dark launches, gradual rollouts, targeted betas, and
a kill switch they control end to end. Single-operator, self-hosted; not a SaaS. Server-side
applications only (PHP and Node backends); no browser SDK.

## Core features (prioritized)

1. **Operator authentication** - Session-based login for the dashboard. No public registration;
   the operator account is created with an artisan command. Everything except the ruleset API and
   the login page requires an authenticated session.

2. **Flags with variants** - Create, list, edit, and archive flags. Each flag has a unique key, a
   name, a description, and a type: `boolean` (fixed variants `true`/`false`) or `string`
   (operator-defined multivariate values). Variant order is fixed at creation; the ruleset
   document references variants by index.

3. **Environments** - `production` and `staging` are seeded; more can be added with an artisan
   command. Each environment has its own SDK key (authenticates ruleset fetches) and signing
   secret (authenticates ruleset content). Every flag has independent per-environment state:
   enabled, killed, off variant, rules, and fallthrough.

4. **Targeting rules** - Ordered rules per flag per environment; first match wins. A rule is a
   set of clauses that all must match; a clause compares a context attribute with an operator:
   `eq`, `in`, `contains`, or a semver comparison (`semver_eq`, `semver_gt`, `semver_gte`,
   `semver_lt`, `semver_lte`). A matching rule serves a fixed variant or a percentage rollout.

5. **Deterministic percentage rollouts** - Rollout weights are basis points (0 to 10000) across
   variants. Bucket = murmur3_32(`{flag_key}:{user_key}`) mod 10000, so assignment is a pure
   function of flag key and user key: identical in both SDKs, stable across polls and restarts,
   and increasing a variant's weight only adds users, never reshuffles existing ones.

6. **Instant kill switch** - One click per flag per environment forces the off variant for every
   user, bypassing all rules and rollouts. The kill publishes a new ruleset version in the same
   transaction; SDKs pick it up on their next poll (bounded by the poll interval, default 30s).

7. **Signed, versioned ruleset distribution** - Each environment has one current ruleset
   document (schema-versioned JSON) rebuilt and re-versioned atomically on every change. Served
   at `GET /api/v1/ruleset` with a strong ETag; SDKs poll with `If-None-Match` and get 304 when
   nothing changed. An HMAC-SHA256 signature over the exact body lets SDKs reject tampered
   documents and keep their last good ruleset.

8. **Local-evaluation SDKs** - `sdks/php` and `sdks/typescript`: fetch, verify, cache, and
   evaluate entirely in-process. Both implement one written evaluation spec and pass one shared
   test-vector file, so a PHP backend and a Node backend give the same user the same variant.

9. **Audit trail** - Every mutation (flag create/edit/archive, per-environment state change,
   rule change, kill, restore) writes an immutable audit row: actor, action, before and after
   snapshots, timestamp. Browsable and filterable in the dashboard.

## Non-goals

- A/B testing statistics: no experiment analysis, no metrics ingestion, no significance math.
- Client-side JavaScript browser SDK; both SDKs are server-side only.
- Streaming updates (SSE/websockets): distribution is polling with ETag only.
- Per-user override UI beyond targeting rules (no "add this one user" pinning screen).
- Flag prerequisites/dependencies between flags.
- Multi-tenant SaaS: no orgs, roles, per-user scoping, or public sign-up.
- Scheduled or progressive automatic rollouts; the operator moves percentages by hand.
- SDK-side event/telemetry export (evaluation counts, flag usage analytics).
- Redis or any cache daemon: ETag revalidation on a plain HTTP endpoint is the distribution.

## Success criteria per core feature

- **Operator authentication** - The artisan-created operator can log in and reach the dashboard;
  wrong credentials show a safe error; dashboard routes without a session redirect to `/login`;
  logout ends the session. No registration route exists.
- **Flags with variants** - A boolean flag gets exactly the `true`/`false` variants; a string
  flag requires 2 to 20 distinct variant values; duplicate flag keys are rejected; archiving
  removes the flag from newly published rulesets but keeps its history and audit trail.
- **Environments** - Fresh install has production and staging with distinct SDK keys and signing
  secrets; a flag's state in staging never leaks into production's ruleset document.
- **Targeting rules** - Rules evaluate strictly in their stored order and short-circuit on first
  match; a clause on a missing attribute fails the clause without erroring; semver operators
  match the written spec including prerelease ordering; all covered by shared test vectors.
- **Deterministic rollouts** - The PHP and TypeScript SDKs produce identical buckets and variants
  for every entry in `sdks/test-vectors/`; raising a variant's weight keeps every previously
  assigned user on that variant (proved by a vector pair before/after the raise).
- **Kill switch** - Killing a flag publishes a new ruleset version synchronously; a poll after
  the kill returns the new version with `killed: true`; every evaluation of that flag returns
  the off variant with reason `killed`; restore reverses it. Worst-case SDK propagation is
  poll interval plus jitter plus one request timeout, documented and tested with a fake clock.
- **Ruleset distribution** - Matching `If-None-Match` returns 304 with an empty body from a
  single indexed query; any config change bumps `version` and changes the ETag; the signature
  header verifies against the exact body bytes; a tampered body or wrong secret is rejected by
  both SDKs, which then keep serving their last good ruleset.
- **Local-evaluation SDKs** - Zero network calls during evaluation; before the first successful
  fetch every evaluation returns the caller's default with reason `not_ready`; a stopped server
  degrades SDKs to their last good ruleset, never to an exception.
- **Audit trail** - Every mutation writes exactly one audit row in the same transaction; the
  trail shows actor, action, timestamp, and a before/after diff; rows are never updated or
  deleted from the dashboard.
