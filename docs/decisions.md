# Design decisions

The engineering choices behind the flagline server, the reasons for them, and the bugs and edge
cases that shaped the current code. `docs/architecture.md` holds the full evaluation spec and data
model; this page explains why the server side is built the way it is.

## 1. Every change publishes synchronously, inside one transaction

All config mutations go through `RulesetPublisher`, the only place the publish transaction is
opened. Inside it the controller's mutation runs, its audit row is written, and the new ruleset
version for each affected environment is built, signed and inserted; they commit together or not
at all. A flag-level change (create, update, archive) publishes to every environment, while a
state, rule or kill change publishes to one. Publishing is synchronous because a queued publish
would open a window where saved config and served document disagree, and building the document
is milliseconds of work. It also keeps the footprint to PHP plus a database, with no queue worker
or Redis. Each audit row records the ruleset version its change published, which is what makes
the trail answer "what was being served at version N".

## 2. Versions are monotonic per environment, with one retry on a lost race

A ruleset's version is `max(version) + 1` for its environment, read inside the transaction, and
`rulesets` has a unique index on `(environment_id, version)`. When two publishes race, the loser's
insert hits the unique index. The publisher retries once in a fresh transaction, so the retry
re-reads config and publishes the merged result instead of the loser's stale view. Only a conflict
on the ruleset insert itself is retried; a unique violation raised by the mutation is surfaced
immediately. A second loss is surfaced rather than retried forever. Because the mutation runs
again, each dashboard mutation re-reads (refreshes) the rows it changes first: a rolled back
attempt leaves the attempted values on the in-memory model, and saving them again would not be
dirty, so the change, a kill included, would be silently dropped from the retry. Feature tests
cover the retry, the no-retry case, and every mutation surviving a retry.

## 3. The stored bytes are what gets signed and served

`RulesetBuilder::encode` produces the exact JSON bytes once, at publish time. Those bytes, their
ETag and their HMAC-SHA256 signature are stored together on an insert-only `rulesets` row, and the
API serves the body verbatim with the signature in `X-Flagline-Signature`. Signing the persisted
bytes rather than embedding a signature in the document avoids a JSON canonicalization problem
that every SDK would otherwise have to solve identically. It also makes serving one indexed read
with no crypto or serialization per request. The ETag is `"<version>-<first 16 hex of
sha256(body)>"`; a matching `If-None-Match` (a comma-separated list is accepted) returns 304 with
no body, and responses carry `Cache-Control: private, no-cache` so clients always revalidate.

## 4. A fresh environment serves a stable version-0 document, not an error

An environment that has never published gets a synthesized document with version 0 and no flags,
signed with its secret. Its `published_at` is the environment's creation time rather than the
current time, so the body, and therefore the ETag and signature, stay identical across requests
and the 304 path still works. Empty `flags` is encoded as a JSON object, not an array, so the
document shape does not change when the first flag appears.

## 5. Variants are referenced by index, so keys and order are immutable

The ruleset document refers to variants by their position in the flag's `sort_order` list, never
by database id. That keeps the document compact and gives rollouts the stable, ordered variant
list they are defined over. The cost is an immutability obligation: a flag's key and type are set
at creation and never mass-updated, the key must match `^[a-z0-9][a-z0-9_-]{0,99}$`, and variant
order never changes. A rollout entry wins when both a fixed variant and a rollout are present on a
serve, so a stale fixed variant can never shadow it.

## 6. The kill switch lives in the document

Killing a flag sets `killed` in that environment's state and publishes a new version through the
same path as any other change, rather than exposing a separate, more frequently polled endpoint.
One distribution path means one story for versioning, signing and caching, and the cost of a kill
is bounded by the SDK poll interval, which the cheap 304 path lets operators keep short. Killing
an already killed flag, or restoring one that is not killed, is a no-op that publishes nothing.

## 7. SDK keys are bearer credentials scoped to one environment

`AuthenticateSdkKey` resolves `Authorization: Bearer <key>` to an environment. A key without the
`fl_sdk_` prefix is rejected without a query; otherwise the key is looked up by an indexed
plaintext column and confirmed with `hash_equals`. A failure is a 401 envelope and a log line with
only the key prefix, never the full key. Signing secrets are different: they never leave the
server, and they are stored with Laravel's `encrypted` cast. The endpoint is read-only and
rate-limited per presented key (120 requests a minute by default). The limiter was first keyed by
the resolved environment, but the throttle middleware runs before the key is resolved, so every
SDK key shared one bucket; it is now keyed by a SHA-256 of the presented token, which also
throttles guesses at unknown keys.

## 8. One JSON error envelope for the whole API

Every error under `/api/*` is rendered as `{ "error": { "code", "message" } }` with its own status:
401 for a missing or unknown key, 404 for an unknown path, 405 for a wrong method, and 429 when
rate-limited. The handler in `bootstrap/app.php` maps those exceptions explicitly, so bad input
never produces a 500 or a stack trace, and details go to the log only.

## 9. Rollouts are basis points that must sum to exactly 10000

A serve is either one fixed variant or a rollout with one weight per variant, as integers from 0 to
10000 that must sum to exactly 10000 and split traffic over at least two variants. Integers in
basis points avoid floating-point drift between implementations. Lowering a weight moves users
out of a variant, so a save that lowers any weight shows a warning. A fixed serve counts as its
variant holding all 10000, so switching from a fixed variant to a rollout warns as well. With two
variants, raising one weight lowers the other, so any change to a two-way rollout warns, which is
correct for what the warning promises.

## 10. Rule ordering works within a unique priority index, without JavaScript

Rules are ordered by `priority` with a unique index on `(flag_environment_id, priority)`, and
first match wins. Swapping two rules directly would collide on that index, so a move parks the
moving rule one above the current maximum, moves its neighbour into the freed slot, then drops
the parked rule into place. Deleting a rule closes the gap by decrementing later rules lowest
first, so no intermediate state duplicates a priority. The dashboard has no JavaScript: adding or
removing a clause row is a form round trip that re-renders the edited rows and saves nothing, and
the variant add and remove buttons on the create screen submit to `GET /flags/create` via
`formaction`, so everything already typed is kept without a POST branch that would have to tell
"add a row" apart from "create the flag".

## 11. New flags start off, and archiving is not mass-assignable

A new flag is created disabled and not killed in every environment. Boolean flags serve `false`
when off and `true` on fallthrough; string flags serve their first variant for both. Off serving
`false` is the conservative choice, and fallthrough serving `true` makes the enable toggle do the
obvious thing without a second edit. `archived_at` was once silently dropped by mass assignment, so
it is deliberately left out of `Flag::$fillable` and set directly by the archive action; no request
payload can archive or resurrect a flag through the create or update forms. Archived flags leave
the published document and the default flag list, and their definition can no longer be edited.

## 12. Ruleset history is insert-only, with a bounded retention window

`rulesets` and `audit_logs` rows are never updated. The one exception is `app:prune-rulesets`,
scheduled daily, which keeps the newest `FLAGLINE_RULESET_KEEP` versions per environment (50 by
default, never fewer than 1) and deletes only versions older than that cutoff. Only the latest row
is ever served, so pruning cannot affect what SDKs receive, and because the latest version is
always kept, `max(version) + 1` stays monotonic after a prune.
