# API Contracts - flagline

Three surfaces exist: the SDK-key-authenticated JSON **ruleset endpoint**, the
session-authenticated **dashboard** (server-rendered HTML, plain form POSTs, not a JSON API),
and the **SDK public APIs** (PHP and TypeScript), which are contracts in the same sense: a
consuming application programs against them. All are agreed here before any code is written.

Base URL: value of `APP_URL`. Timestamps are ISO-8601 UTC.

## Error envelope (all API JSON errors)

```json
{
  "error": {
    "code": "unauthorized",
    "message": "The SDK key is missing or not recognized."
  }
}
```

### Stable error codes

| HTTP | `error.code` | When |
|---|---|---|
| 401 | `unauthorized` | `Authorization: Bearer` header missing, malformed, or key unknown. |
| 404 | `not_found` | Any other `/api/*` path. |
| 405 | `method_not_allowed` | Any method other than GET on the ruleset URL. |
| 429 | `rate_limited` | Per-SDK-key throttle exceeded (default 120 requests/minute). |
| 500 | `server_error` | Unexpected error (details logged, never returned). |

Auth failures are logged with the key prefix (`fl_sdk_ab...`), never the full key.

---

## Ruleset endpoint

### GET /api/v1/ruleset

Auth: `Authorization: Bearer <sdk_key>`. The key identifies the environment; there is no
environment parameter. Read-only; this is the only API route.

First fetch:

```
GET /api/v1/ruleset HTTP/1.1
Authorization: Bearer fl_sdk_k3v9x2m8q1w5e7r4t6y8u0i2o4p6a8s0
```

```
HTTP/1.1 200 OK
Content-Type: application/json
ETag: "42-9f86d081884c7d65"
X-Flagline-Signature: sha256=4d1f7ab5e2c9d8f0a3b6c1e4d7f0a3b6c1e4d7f0a3b6c1e4d7f0a3b6c1e4d7f0
Cache-Control: private, no-cache
```

```json
{
  "schema_version": 1,
  "environment": "production",
  "version": 42,
  "published_at": "2026-07-27T12:00:00Z",
  "flags": {
    "checkout-redesign": {
      "type": "boolean",
      "enabled": true,
      "killed": false,
      "variants": ["true", "false"],
      "off_variant": 1,
      "rules": [
        {
          "clauses": [
            { "attribute": "plan", "op": "in", "values": ["pro", "enterprise"] },
            { "attribute": "app_version", "op": "semver_gte", "values": ["2.4.0"] }
          ],
          "serve": { "variant": 0 }
        }
      ],
      "fallthrough": {
        "rollout": [
          { "variant": 0, "weight": 2000 },
          { "variant": 1, "weight": 8000 }
        ]
      }
    },
    "pricing-page-copy": {
      "type": "string",
      "enabled": true,
      "killed": false,
      "variants": ["control", "value-first", "social-proof"],
      "off_variant": 0,
      "rules": [],
      "fallthrough": {
        "rollout": [
          { "variant": 0, "weight": 3400 },
          { "variant": 1, "weight": 3300 },
          { "variant": 2, "weight": 3300 }
        ]
      }
    }
  }
}
```

Poll when nothing changed (the steady state):

```
GET /api/v1/ruleset HTTP/1.1
Authorization: Bearer fl_sdk_k3v9x2m8q1w5e7r4t6y8u0i2o4p6a8s0
If-None-Match: "42-9f86d081884c7d65"
```

```
HTTP/1.1 304 Not Modified
ETag: "42-9f86d081884c7d65"
```

Empty body, no signature header needed (the SDK keeps its verified copy). A fresh environment
with no publishes serves `{"schema_version":1,"environment":"...","version":0,"flags":{}}`
with a normal ETag and signature.

Field semantics, the evaluation algorithm, bucketing, and schema versioning rules are
normative in `docs/architecture.md`; the shapes above are exhaustive for schema version 1: a
`serve` (and a `fallthrough`) is exactly `{"variant": <int>}` or
`{"rollout": [{"variant": <int>, "weight": <int>}]}` with weights in basis points summing to
10000, variants are referenced by index, and clause `op` is one of `eq`, `in`, `contains`,
`semver_eq`, `semver_gt`, `semver_gte`, `semver_lt`, `semver_lte`.

### Signature verification (what every SDK must do)

`X-Flagline-Signature` is `sha256=<lowercase hex hmac_sha256(signing_secret, body)>` computed
over the exact response body bytes. Verify before parsing, with a constant-time compare:

```php
$expected = 'sha256=' . hash_hmac('sha256', $body, $signingSecret);
if (!hash_equals($expected, $header)) { /* discard, keep last good ruleset */ }
```

```typescript
const expected = "sha256=" + createHmac("sha256", secret).update(body).digest("hex");
const ok = expected.length === header.length
  && timingSafeEqual(Buffer.from(expected), Buffer.from(header));
```

A failed verification is never an exception to the host app: the SDK discards the body, keeps
its last good ruleset, and invokes the error callback with `signature_mismatch`.

---

## SDK public API

Both SDKs expose the same surface with language-native naming. Constructor options:

| Option | Default | Meaning |
|---|---|---|
| `baseUrl` | required | The flagline server, e.g. `https://flags.internal.example.com`. |
| `sdkKey` | required | Environment SDK key (bearer credential). |
| `signingSecret` | required | Environment signing secret (verification credential). |
| `pollSeconds` | 30 | Poll interval; minimum 5. Jitter of plus or minus 10 percent is applied per poll. |
| `timeoutSeconds` | 3 | Per-request timeout. |
| `onError` | no-op | Callback `(reason, detail)`; reasons: `network`, `http_status`, `signature_mismatch`, `schema_version`. |

Worst-case change propagation (including a kill) is
`pollSeconds + 10 percent jitter + timeoutSeconds`: 36.0 seconds at defaults. Lower
`pollSeconds` to tighten it at the cost of request volume; the 304 path keeps that cheap.

### PHP (`composer require flagline/flagline-php`)

```php
use Flagline\Client;

$client = new Client([
    'base_url' => 'https://flags.internal.example.com',
    'sdk_key' => getenv('FLAGLINE_SDK_KEY'),
    'signing_secret' => getenv('FLAGLINE_SIGNING_SECRET'),
    'poll_seconds' => 30,
    'on_error' => fn (string $reason, string $detail) => $log->warning("flagline: $reason"),
]);
$client->start();                       // first fetch + background polling

$context = ['key' => 'user-42', 'attributes' => ['plan' => 'pro', 'app_version' => '2.5.1']];

$client->boolVariation('checkout-redesign', $context, false);   // bool, default on any failure
$client->variation('pricing-page-copy', $context, 'control');   // string variant value
$detail = $client->variationDetail('checkout-redesign', $context, false);
// ['value' => true, 'reason' => 'rule_match', 'rule_index' => 0]

$client->stop();
```

Long-running processes (Octane, queue workers) poll in the background; classic PHP-FPM
request-scoped usage performs at most one conditional fetch per request when the cached
document is older than `pollSeconds` (documented in the SDK README; bootstrap files are
backlog).

### TypeScript (`npm install flagline-node`)

```typescript
import { FlaglineClient } from "flagline-node";

const client = new FlaglineClient({
  baseUrl: "https://flags.internal.example.com",
  sdkKey: process.env.FLAGLINE_SDK_KEY!,
  signingSecret: process.env.FLAGLINE_SIGNING_SECRET!,
  pollSeconds: 30,
  onError: (reason, detail) => logger.warn({ reason, detail }, "flagline"),
});
await client.start();                   // resolves after the first fetch attempt

const context = { key: "user-42", attributes: { plan: "pro", app_version: "2.5.1" } };

client.boolVariation("checkout-redesign", context, false);
client.variation("pricing-page-copy", context, "control");
client.variationDetail("checkout-redesign", context, false);
// { value: true, reason: "rule_match", ruleIndex: 0 }

client.stop();
```

### Shared behavioral contract

- `variation` and `boolVariation` never throw and never touch the network; they read the
  in-memory document only. `boolVariation` maps the variant strings `"true"`/`"false"`;
  calling it on a string flag returns the default with reason `wrong_type`.
- Reasons, exhaustively: `not_ready`, `flag_not_found`, `killed`, `off`, `rule_match` (with
  rule index), `fallthrough`, `no_user_key`, `wrong_type`.
- `variationDetail` returns value, reason, and the rule index when reason is `rule_match`.
- Identical inputs produce identical outputs across both SDKs; enforced by
  `sdks/test-vectors/` and the parity script.

---

## Test vector file formats

`sdks/test-vectors/bucketing.json`:

```json
[
  { "flag_key": "checkout-redesign", "user_key": "user-42",
    "hash": 111560078, "bucket": 78 }
]
```

`sdks/test-vectors/evaluation.json`:

```json
[
  {
    "name": "rollout assigns user-42 to first variant at 2000bp",
    "document": { "schema_version": 1, "environment": "test", "version": 1, "flags": { } },
    "context": { "key": "user-42", "attributes": {} },
    "flag_key": "checkout-redesign",
    "default": "false",
    "expected_value": "true",
    "expected_reason": "fallthrough"
  }
]
```

Documents inside vectors are complete schema-v1 documents (elided above). Expected values are
the variant strings; boolean conversion is tested separately per SDK.

---

## Dashboard routes (HTML, not JSON)

All routes below except login require an authenticated session; unauthenticated requests
redirect to `/login`. All POSTs carry a CSRF token. Validation errors re-render the form with
field errors; success redirects with a flash message. One operator role. The environment
switcher is the `env` query parameter, defaulting to production.

| Method | Path | Purpose |
|---|---|---|
| GET | `/login` | Login form (guests only). |
| POST | `/login` | Attempt login; throttled by IP. |
| POST | `/logout` | End session. |
| GET | `/flags` | Flag index for the selected environment; filters: `q`, `type`, `archived`. |
| GET | `/flags/create` · POST `/flags` | Create flag (key, name, description, type, variants). |
| GET | `/flags/{key}/edit` | State card, rule builder, fallthrough card for the selected env. |
| PUT | `/flags/{key}` | Update name/description. |
| POST | `/flags/{key}/archive` | Archive (confirmed); drops from future rulesets. |
| PUT | `/flags/{key}/environments/{env}` | Update enabled, off variant, fallthrough serve. |
| POST | `/flags/{key}/environments/{env}/kill` | Kill switch (confirmed); publishes synchronously. |
| POST | `/flags/{key}/environments/{env}/restore` | Clear the kill; publishes synchronously. |
| POST | `/flags/{key}/environments/{env}/rules` | Add a rule (with its first clause row). |
| PUT | `/rules/{id}` | Update rule description, clauses, and serve. |
| POST | `/rules/{id}/move` | Move up/down; priorities resequenced contiguously. |
| DELETE | `/rules/{id}` | Delete rule (confirmed). |
| GET | `/environments` | Read-only list; keys revealed behind a disclosure. |
| GET | `/audit` | Paginated trail; filters: `flag_id`, `environment_id`, `action`. |
| GET | `/up` | Framework health check (public, no body contract). |

Access summary: `GET /api/v1/ruleset` (bearer) and `GET /up` are the only routes reachable
without a session; login is throttled; everything else is operator-only. Every mutating route
above publishes a new ruleset version for its environment via `RulesetPublisher` and writes
one audit row; flag create/archive publishes for all environments.

## Artisan commands

| Command | Purpose |
|---|---|
| `php artisan app:create-user {email}` | Create the operator (prompts for password). |
| `php artisan app:create-environment {name}` | Create an environment; prints the SDK key and signing secret once. |
