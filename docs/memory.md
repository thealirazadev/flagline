# Project Memory - flagline

Running log of what is done, in progress, and decided. Update after every meaningful chunk of
work; log every non-obvious decision with its reason. Keep entries short and dated.

## Completed

- 2026-07-27 - Planning documentation created (README, PRD, architecture, rules, phases,
  design, testing, api-contracts, launch-checklist, memory). No code yet; docs under owner
  review. Bucketing reference values in `docs/architecture.md` were computed with a
  MurmurHash3_x86_32 implementation verified against the algorithm's published reference
  vectors, so the numbers in the docs are real, not illustrative.

- 2026-07-28 - Phase 1 delivered in 12 commits: Laravel scaffold, env example and
  `config/flagline.php`, operator login plus `app:create-user`, environments migration/model/
  seeder, `app:create-environment`, flags and variants, per-environment state, flag CRUD
  screens, the state form, audit writes and the trail page, the environments page with key
  reveal, and the test suite. Verified: `php artisan test` 54 passed (180 assertions),
  `./vendor/bin/pint --test` clean, `migrate:fresh --seed` yields production and staging with
  distinct `fl_sdk_*` keys and encrypted `fl_sig_*` secrets. Walked the whole checklist against
  a live server: root redirects to `/flags`, guests are bounced to `/login`, `/register` is 404,
  a wrong password gives the safe message, boolean flags get exactly `true`/`false`, duplicate
  and malformed keys are rejected with field errors, enabling in staging leaves production off,
  archived flags leave the default index but stay under the archived filter and go read-only,
  and each of the three mutations wrote exactly one audit row.

## Project status

- Phase 1 complete and pushed. Phase 2 (atomic publishing and signed ruleset distribution) is
  next and not started. No `rulesets` table, `RulesetPublisher`, `RulesetBuilder`,
  `RulesetSigner`, API route, or kill switch exists yet, which is why Phase 1 mutations sit in
  plain `DB::transaction` blocks that write the audit row alongside the config change. Phase 2
  reroutes exactly those call sites through `RulesetPublisher`.

## Decisions log

- 2026-07-27 - Variants are referenced by array index in the ruleset document, not by database
  id. Reason: the document stays compact and the evaluation spec needs a stable, ordered
  variant list anyway (rollout ranges are defined over it). The cost is an immutability
  obligation: `variants.sort_order` and flag keys can never change after creation, and string
  flag variants are append-only. That obligation is written into the data model and rules
  rather than exposing database ids to SDKs.
- 2026-07-27 - MurmurHash3_x86_32 with seed 0 chosen as the bucketing hash. Reason: PHP 8.1+
  ships it natively (`hash('murmur3a')`), so the PHP SDK needs zero dependencies, and a
  dependency-free TypeScript implementation is about 40 lines with published reference vectors
  to prove it. sha256-based bucketing was rejected because taking "the first N bits" invites
  subtle byte-order divergence between languages; murmur3's canonical 32-bit output plus the
  shared vectors file makes cross-SDK identity checkable, and the vectors are declared the
  arbiter over any residual ambiguity.
- 2026-07-27 - The HMAC signature is delivered in a response header over the exact stored body
  bytes, not embedded in the document. Reason: signing the bytes that were persisted at
  publish time (and serving them verbatim) eliminates the JSON canonicalization problem
  entirely; an embedded signature would require a canonical serialization of "the document
  minus the signature field" in three codebases. Storing body, etag, and signature together on
  the immutable rulesets row also makes the 304 path a single indexed read with no crypto work
  per request.
- 2026-07-27 - The kill switch is a boolean inside the ruleset document, not a separate
  lightweight endpoint the SDKs poll more often. Reason: one distribution path means one
  correctness story (versioning, signing, caching) instead of two, and the propagation bound
  (`pollSeconds + jitter + timeout`, 36s at defaults) is honest and documented rather than an
  illusion of instant. Operators who need a tighter bound lower `pollSeconds`; the 304 path
  keeps even 5s polling cheap. Streaming is an explicit PRD non-goal.
- 2026-07-27 - Publishes are synchronous inside the mutation transaction (no queue anywhere in
  the app). Reason: the atomicity invariant (config change, audit row, and new version commit
  together) is the backbone of the audit trail's trustworthiness and of "what you saved is
  what is served"; a queued publish would open a window where config and served document
  disagree with nothing to show for it, since document building is milliseconds of work. This
  also keeps the self-host footprint to PHP plus a database, matching the Redis-free
  distribution goal.

- 2026-07-28 - Scaffolded on Laravel 12.64.0, not the 11.x the docs name. Reason: at scaffold
  time 11.x is two majors behind (13.x is current) and outside its security-fix window, so
  pinning it would ship an unpatched framework; 12.64.0 is actively maintained, released
  2026-07-14, and `composer audit` reports no advisories against the resulting lockfile. It is
  also the line the sibling laravel projects in this workspace already run, so one PHP toolchain
  covers all of them. Nothing in `docs/architecture.md` depends on an 11.x-only API. This
  resolves the open item that was under "Flagged for owner review"; PHP stays at 8.2+, which
  keeps the native `hash('murmur3a')` the bucketing spec relies on.
- 2026-07-28 - Verified the three published bucketing vectors in `docs/architecture.md` against
  this machine's PHP before writing any code, because both SDKs will later have to agree with
  them: `hash('murmur3a')` plus `hexdec` gives 111560078/78 for `checkout-redesign:user-42`,
  2215518393/8393 for `checkout-redesign:user-43`, and 2161741713/1713 for
  `new-pricing:user-42`, all three matching the doc exactly. No bucketing code ships in Phase 1;
  this was a correctness check on the spec ahead of Phase 4.
- 2026-07-28 - New flags default to off everywhere with the off variant set to `false` and the
  fallthrough set to `true` for boolean flags (both to variant index 0 for string flags).
  Reason: the architecture fixes "new flags start off everywhere" but not the initial serves,
  and `flag_environments` requires exactly one non-null fallthrough serve, so a default was
  unavoidable. Off serving `false` is the conservative choice, and fallthrough serving `true`
  makes the enable toggle do the obvious thing without a second edit.
- 2026-07-28 - `archived_at` is deliberately absent from `Flag::$fillable`; the archive path
  assigns it directly. Reason: it was silently dropped by mass assignment during Phase 1, and
  keeping it unfillable means no request payload can archive or resurrect a flag through the
  create or update forms.
- 2026-07-28 - The create screen's variant add/remove buttons submit the same form back to
  `GET /flags/create` via `formmethod`/`formaction`. Reason: `docs/design.md` requires no-JS
  round trips, and routing them through the GET route keeps everything already typed without
  adding a POST branch that would have to distinguish "add a row" from "create the flag".

## Unverified

- Nothing in Phase 1 is unverified. The suite, Pint, the migrations, both artisan commands, and
  every checklist item were run and observed locally. The one environment quirk worth recording:
  `php artisan serve` cannot start on this machine because it re-execs the raw PHP binary
  without the bundled `LD_LIBRARY_PATH` that the `php` wrapper sets, so it dies on
  `libtidy.so.5deb1`. The live walkthrough used
  `php -S 127.0.0.1:8124 -t . ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`
  from `public/` instead. This is a local toolchain artifact, not an application defect, and it
  does not affect `php artisan test`.
