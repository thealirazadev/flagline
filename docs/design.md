# Design - flagline

The dashboard is a deliberately boring, server-rendered operations UI: one static stylesheet
(`public/css/app.css`), no CSS framework, no JavaScript build, no client-side state. Every
screen is a document: tables, forms, definition lists, and links. The rule builder, the most
form-heavy screen, works entirely through round-trip form posts.

## Screens

- **Login** (`/login`) - email, password, submit. Throttle and error messaging per
  `docs/rules.md`.
- **Flag index** (`/flags`) - the home screen. Environment switcher (a segmented link row:
  production, staging, any extras) scopes the whole page. Table: flag key (monospace, links to
  edit), name, type badge, per-selected-environment status badge, last-changed timestamp, and
  a kill/restore button per row. Filter row: text search on key/name, type select, archived
  toggle. Empty state links to flag creation.
- **Flag create** (`/flags/create`) - key (with pattern hint and immutability warning), name,
  description, type radio; choosing `string` reveals a server-rendered list of variant value
  inputs (add/remove via round-trip buttons). On save, redirect to edit.
- **Flag edit** (`/flags/{key}/edit`) - one page, three stacked cards, all scoped to the
  selected environment (switcher persists in the query string):
  1. **State card**: enabled toggle, off variant select, kill/restore button with the killed
     state called out in a danger-toned banner when active.
  2. **Rules card**: ordered rule list. Each rule renders its description, its clause rows
     (attribute, operator, values), its serve, and up/down/delete buttons. "Add rule" and
     per-rule "add clause" are form posts that re-render the page with the new empty row.
  3. **Fallthrough card**: fixed variant select, or rollout mode with one basis-point weight
     input per variant and a live server-computed sum shown after save attempts. The shrink
     warning appears as a flash notice after a save that lowered any weight.
- **Environments** (`/environments`) - read-only table: name, SDK key and signing secret each
  behind a no-JS `<details>` disclosure, created date, latest ruleset version.
- **Audit trail** (`/audit`) - filter row (flag, environment, action) over a paginated table:
  timestamp, actor, action, flag, environment, published version, and an expandable
  before/after block (`<details>`, escaped JSON, monospace).

## States

Every interactive element defines default, hover, focus, disabled. Screens define error,
empty, and (server-rendered) loading is the browser's own. Mutations redirect-after-post with
a flash message. Destructive actions (archive, kill, delete rule) confirm via an inline no-JS
`<details>` confirm form, never `confirm()`.

- **Empty states** - Every index explains itself and offers the next action ("No flags yet.
  Create your first flag."). Filtered-to-empty states say so and offer "Clear filters".
- **Killed state** - Unmissable: danger-toned row tint on the index plus the banner on edit.
  Killed is visually louder than disabled; it is the emergency state.
- **Validation errors** - Field-level, adjacent to the input, values repopulated. The rollout
  sum error names the actual sum ("weights sum to 9000, need 10000").

## Color & theme

Light theme only in v1. Neutral grays, one accent, and a fixed status palette. Tokens:
`--bg #f6f7f9`, `--surface #ffffff`, `--border #d9dde3`, `--text #1f2933`,
`--text-muted #57606a`, `--accent #1d4ed8`, `--accent-hover #1e40af`, `--danger #b91c1c`.

Status badges (background / text, all AA; the label always accompanies the color):

| Status | Background | Text |
|---|---|---|
| `on` (enabled) | `#d1fae5` | `#065f46` |
| `off` (disabled) | `#e5e7eb` | `#374151` |
| `killed` | `#fee2e2` | `#991b1b` |
| `archived` | `#f3f4f6` | `#6b7280` |
| type `boolean` / `string` | `#dbeafe` | `#1e40af` |

## Typography, spacing, components

- System font stack for UI; `ui-monospace` stack for flag keys, SDK keys, JSON, and versions.
  Scale (rem): page title 1.5/600, section heading 1.125/600, body 0.9375, small 0.8125.
- Spacing on a 4/8px system; cards 16px padding, page gutter 24, max content width 1200px.
  Radius 6px (cards/inputs/buttons), pills only for status badges. One subtle card shadow.
- Buttons: primary (accent), secondary (bordered), danger (kill, archive, delete rule).
  Links underline on hover/focus. Tables: rules not zebra, monospace ids with ellipsis +
  `title`, wide tables scroll inside the card, never the page.
- Forms: visible `<label>` per input; clause rows group attribute/operator/values in a
  `<fieldset>` per rule with a `<legend>`; weight inputs are `type="number"` with min/max.

## Accessibility baseline

- Semantic HTML: one `<h1>` per page, `<nav>`/`<main>` landmarks, real `<table>`, real
  `<button>`/`<a>`, `<html lang="en">`.
- Fully keyboard-navigable; the entire rule builder must pass a keyboard-only run (add rule,
  add clause, reorder, save) because it is all forms.
- Visible focus: global 2px accent outline with offset; never removed without replacement.
- Contrast AA everywhere; status conveyed by text plus color, never color alone.
- Layout readable at 320px: tables scroll, forms stack, the environment switcher wraps.

## Error pages

Framework 404 and 419 pages are replaced with minimal branded Blade pages using the same
layout. The API never renders HTML; JSON envelope only, per `docs/api-contracts.md`.
