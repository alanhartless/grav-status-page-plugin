# Changelog

## Unreleased

- **Fixed:** confirmed live -- a category with an active partial-outage announcement and a watching full-outage announcement showed `outage`, not `partial-outage`. A `watching` announcement's own severity should never contribute to the severity-based level at all; only `active` announcements decide it. The `watching` level is now a genuine fallback used only when nothing is confirmed still active.
- **Fixed:** the public status page sent `Cache-Control: max-age=604800` (Grav core's 7-day default) on every response, since disabling Grav's own server-side render cache (`cache_enable: false`) says nothing about what a browser does with the response. A browser honoring that header serves its own week-old cached copy and never asks the server again -- confirmed as the actual cause of needing a manual cache-clear plus a hard-refresh to see a saved announcement change. Now sends `Cache-Control: no-store`.

- **Added:** a new `watching` status level for the overall banner and category badges. Once every currently-live (non-resolved) announcement affecting a category is specifically in the `watching` state -- none still `active` -- it's shown as `watching` (a new warning color, `--status-watching`) instead of the severity-based outage/partial-outage color. A single still-`active` announcement anywhere in the live set falls straight through to the normal severity-based level, since that means something is confirmed still happening.
- **Added:** the overall banner's watching and outage messages are now configurable (`banner_message_watching`, `banner_message_outage` in the plugin's config), defaulting to "All systems have recovered and we're actively monitoring the situation." and "Some systems are experiencing an outage." respectively.

- Announcement title made explicitly larger and bolder (1.35rem, weight 700) than the body text below it -- previously relied on `<h3>`'s default styling, which a host theme's own heading resets could (and did) leave no more prominent than body copy.

- **Fixed:** an announcement's title/state row picked up unwanted padding from a host theme's own base styles for the generic `<header>` HTML element. Switched to a plain `<div>` -- a theme-agnostic plugin shouldn't rely on a host theme never styling common semantic tags it happens to reuse for unrelated purposes.
- **Fixed:** category badges could show a bullet point depending on the host theme's typography/prose styles, which can target `li` directly with a selector specific enough to beat `list-style: none` set only on the parent `<ul>`. Now also set directly on the badge `<li>` itself.

- Announcement severity badge moved back to sharing a row with the category badges (categories left, severity right), instead of sharing a row with the dates. Dates are their own line again.

- **Fixed:** every status color (banner, category badges, the daily history strip, announcement badges) rendered with no color at all -- devtools reported every `--status-*` custom property as undefined, even with a host theme's `:root` mapping active. Root cause: `.status-page { --status-operational: var(--status-operational, #hex); ... }` is a CSS custom-property self-reference cycle, invalid at computed-value time per spec -- it does not fall through to an ancestor's value, it's just broken. Fixed by removing the redeclaration entirely and moving the fallback onto every `var()` call site that actually consumes each color, the standard pattern for "plugin ships a default, host overrides via the same custom property name."

- **Fixed:** resolving an outage/partial-outage announcement left the overall banner and its category's status badge stuck on the old severity until midnight. Root cause: both were reusing the day-by-day history strip's *today* cell, which is (correctly) a permanent per-day record -- a day that had an outage stays marked as having had one even after it's resolved later the same day. The banner and category badge now use a new, separate live-status check (`StatusProjector::liveStatus()`): the worst severity among announcements that are still actually `active`/`watching`, right now. The history strip's per-day record is unaffected.

- **Workaround:** the category-chip display bug on an announcement's edit form (a selected chip shows its raw key instead of its title) is a confirmed Admin2 bug -- its select field resolves a chip's label via `optionsMap.get(storedValue) ?? storedValue`, and that lookup misses for an already-saved value due to a timing/reactivity gap in when Admin2 considers its options list ready, even though the identical lookup correctly labels the dropdown suggestions. Not fixable from this plugin's blueprint. Worked around by presenting `categories` as titles (not keys) specifically in the Admin2-facing API response (`jsonSerialize()`) -- the fallback then displays correctly regardless of the timing bug. Storage, the public page, and `StatusProjector` matching are all untouched (still key-based); whatever Admin2 submits back on save is already normalized to a real key by the existing `normalizeToKeys()` call.

- **Reverted:** a same-day attempted fix for the category-chip display bug (returning categories `options` as a `{value, label}` array instead of a `key => title` map) broke creating and editing every announcement outright ("Failed to load blueprint"). Root cause: `BlueprintController::serializeFields()` (the `api` plugin) already converts a resolved `key => title` map into that exact array shape itself, right after resolving `data-options@` -- handing it the array shape directly made that conversion run twice, which throws in this environment (PHP's array-to-string conversion warning is escalated to a fatal exception here). The category-chip display bug itself remains open and unresolved; `options()` is back to the plain `key => title` map, which is correct.

- **Fixed:** a newly *created* announcement stored whatever titles Admin2's categories multi-select submitted, uncorrected -- the canonicalization added for the `commalist`/`array` fix above only ran inside `update()`, and flex-objects' own create endpoint never calls `update()` on the initial save (`createObject()` + `save()` directly). Categories are now canonicalized to keys in the constructor too, which runs on every create *and* every load -- as a side effect, an announcement saved with titles before this fix now heals automatically on next read, with no manual re-save needed.

- **Fixed:** the `categories` field on an announcement now correctly stores category keys instead of display titles. Previously validated as `commalist` (meant for a plain comma-separated text field); a `type: select, multiple: true` field needs `array`. This silently broke every key-based lookup downstream -- an active outage never colored its category or the overall banner, and the category badge never appeared. Categories are also now canonicalized to their key server-side regardless of what the admin form submits, so a stale save can't reintroduce the same corruption. **Any announcement saved before this fix needs its Categories field re-selected once** to correct the stored value.
- `window_days` capped at 365 (was 3650).
- **Fixed:** a category's machine-name field is renamed `key` -> `slug`. `key` collided with a reserved object-identity property in Admin2's own Flex API payloads, which silently failed to populate the field's value into the edit form and then failed save with a spurious required-field error. The field's value is now correctly restored on edit (both the object-level API response and the plain form-value path). `slug` is also now generated automatically from the Title when a category is created, and permanently readonly/disabled in the form afterward -- confirmed there's no way for Admin2 to serve a different schema for "add" vs. "edit" (both fetch the same directory-level blueprint), and confirmed separately that Grav's Flex storage does not rename the underlying file just because a field value changes, so an editable slug on an existing category would have appeared to save while silently doing nothing real.
- An announcement's categories render as badges under the title, instead of a plain comma-separated line.
- Announcements now also show a severity badge (Partial outage / Outage), floated right of the category badges. A severity of `none` renders no badge.

## 1.0.0

Initial release.

- **Flex data model.** `status-categories` and `status-announcements` Flex
  Objects types, authorable through Grav's Admin panel. Both store one YAML
  file per object under `user/data/flex-objects/`, registered
  programmatically (no `user/config` edits required). Cross-field validation
  (`ended_at` required once `state` is `resolved`, `ended_at >= started_at`)
  is enforced in code on every write path, not only in the admin form.
- **Status projection.** `StatusProjector`: pure, framework-free computation
  of a category's current status, N-day history strip, and uptime
  percentage from plain announcement arrays. Handles interval-overlap
  severity resolution and open-ended-incident rules (an active/watching
  incident with no `ended_at` runs to "now"; a resolved one ends at its own
  last-modified time, never open-ended), with a configurable window length
  and partial-outage weight -- neither hardcoded. Exhaustively unit-tested,
  including DST transitions and malformed hand-edited input.
- **The public status page.** A plugin-provided route (`onPagesInitialized`
  + `Pages::addPage()`, no page file under `user/pages`), rendering an
  overall banner, active/watching announcements, each category's N-day
  strip and uptime, and resolved announcements from the last `window_days`
  days. The page disables Grav's page cache so the strip never freezes on a
  stale render. Announcement bodies render through Grav's own Markdown
  pipeline, then a `status_sanitize_html` Twig filter
  (`rhukster/dom-sanitizer`) before reaching the page. The template extends
  a configurable `base_template` and degrades to a fully self-contained
  standalone layout when that config is empty; colors are CSS custom
  properties with built-in fallbacks, so the plugin ships no host-specific
  color values.
- Blueprint `permissions:` blocks, `user-data://`-only Flex storage paths,
  a single `addAllowedDynamicCallable` registration for the categories
  option provider, and Twig auto-escaping/sanitization of announcement
  bodies were all verified as part of this release.
