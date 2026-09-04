# Changelog

## Unreleased

- **Fixed:** the `categories` field on an announcement now correctly stores category keys instead of display titles. Previously validated as `commalist` (meant for a plain comma-separated text field); a `type: select, multiple: true` field needs `array`. This silently broke every key-based lookup downstream -- an active outage never colored its category or the overall banner, and the category badge never appeared. **Any announcement saved before this fix needs its Categories field re-selected once** to correct the stored value.
- `window_days` capped at 365 (was 3650).
- Editing an existing category now correctly prepopulates the Key field.
- An announcement's categories render as badges under the title, instead of a plain comma-separated line.

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
