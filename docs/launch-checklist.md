# Launch Checklist - flagline

Work top to bottom before going to production. Nothing is checked until verified in the target
environment.

## Environment & configuration

- [ ] Production `.env` created from `.env.example` with real values (no dummies).
- [ ] `APP_KEY` generated and backed up (rotating it later invalidates stored encrypted
      signing secrets).
- [ ] `APP_DEBUG=false`, `APP_ENV=production`; `APP_URL` set to the real host.
- [ ] Database chosen deliberately: SQLite file on durable storage with backups, or `DB_*`
      pointing at production PostgreSQL with credentials stored securely.
- [ ] `FLAGLINE_RULESET_KEEP` reviewed; cron entry for `php artisan schedule:run` (prune).
- [ ] Config/route/view caches warmed (`config:cache`, `route:cache`, `view:cache`).

## Security

- [ ] No secrets committed; `.env` git-ignored; only `.env.example` (dummies) tracked.
- [ ] HTTPS enforced end to end; SDK base URLs are https only (the bearer key travels in a
      header).
- [ ] Operator account created via `app:create-user`; no registration route reachable.
- [ ] Login throttle and per-SDK-key API throttle active; 429 envelope confirmed.
- [ ] Signing secrets confirmed encrypted at rest (inspect an `environments` row).
- [ ] Production and staging SDK keys distributed to the right apps and never crossed
      (staging key in a production app would serve staging flags silently).
- [ ] Audit snapshots spot-checked as escaped in the dashboard (create a flag whose name
      contains HTML; view the trail).

## Distribution & propagation

- [ ] A real SDK (either language) polls production: 200 on first fetch, 304s afterward,
      signature verifies.
- [ ] Kill switch drill: kill a test flag, measure time until a polling SDK serves the off
      variant; confirm it is within `pollSeconds + jitter + timeout` and the team knows this
      bound.
- [ ] No shared cache in front of `/api/v1/ruleset` adds `s-maxage`; if one does, the added
      seconds are documented next to the kill bound.
- [ ] Server-death drill: stop the app, confirm SDK-consuming services keep serving last-good
      variants and log the error callback; restart, confirm recovery without intervention.

## Reliability & observability

- [ ] Error tracking / log aggregation receiving `ruleset.*`, `flag.killed`, `audit.recorded`,
      `auth.login_failed`.
- [ ] Database backups scheduled and a restore tested at least once (config, rulesets, and the
      audit trail all live there).
- [ ] Migrations run cleanly on production (`migrate --force --seed` on first deploy).
- [ ] Concurrent-save drill on staging: two operators edit the same flag; both changes audited,
      versions contiguous, final document contains the merged truth.
- [ ] Prune observed running with a sane `prune.completed` count; latest version untouched.

## Quality gates

- [ ] App suite, PHP SDK suite, and TypeScript SDK suite green in CI on the release commit.
- [ ] Parity script reports zero divergence on the release commit.
- [ ] `./vendor/bin/pint --test` clean; `tsc --noEmit` clean.
- [ ] Lockfiles (`composer.lock` in root and `sdks/php`, `package-lock.json` in
      `sdks/typescript`) committed and matching the deployed build.
- [ ] Every dashboard index checked in the empty state and at 320px width.
