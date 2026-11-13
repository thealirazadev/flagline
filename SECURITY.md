# Security policy

## Supported versions

Only the latest commit on `main` receives security fixes.

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting
(Security tab, "Report a vulnerability") or email thealirazadev@gmail.com with steps to
reproduce. You should get a reply within 72 hours.

## How flagline protects flag data

- SDK keys are bearer credentials that only read the ruleset of one environment; unknown
  or malformed keys get a 401 envelope and are logged by prefix, never in full.
- Rulesets are signed with HMAC-SHA256 under a per-environment signing secret that is
  stored encrypted and never transmitted; SDKs verify the exact bytes before parsing.
- `GET /api/v1/ruleset` is throttled per presented key (default 120 requests/minute).
- Every dashboard change is audited with before/after state and published atomically.
- Rule input is validated server-side: operator enum, value counts and lengths, semver
  values, and rollout weights that must sum to exactly 10000.
