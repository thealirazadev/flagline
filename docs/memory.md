# Project Memory - flagline

Running log of what is done, in progress, and decided. Update after every meaningful chunk of
work; log every non-obvious decision with its reason. Keep entries short and dated.

## Completed

- 2026-07-27 - Planning documentation created (README, PRD, architecture, rules, phases,
  design, testing, api-contracts, launch-checklist, memory). No code yet; docs under owner
  review. Bucketing reference values in `docs/architecture.md` were computed with a
  MurmurHash3_x86_32 implementation verified against the algorithm's published reference
  vectors, so the numbers in the docs are real, not illustrative.

## Project status

- Planning stage. Implementation follows `docs/phases.md` (five phases), starting with
  Phase 1 after owner approval of these documents.

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

## Flagged for owner review

- Laravel 11.x is specified per the project brief. The sibling hook-relay project later moved
  to 12.x because security advisories in its dependency set were only patched there; if the
  same situation holds at scaffold time, the coding agent should surface it before pinning
  rather than silently choosing either line.
