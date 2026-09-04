# Changelog

## 1.0.0

First tagged release. A scoped security + correctness review of the full
tracked tree (blueprint permissions, Flex storage paths, the dynamic-callable
allowlist, Twig auto-escaping/sanitization, and general correctness) found
two robustness gaps, both fixed here:

- `StatusProjector` and `AnnouncementSections` no longer crash the whole
  public page when a single `status-announcements` record has an
  unparseable `started_at`/`ended_at` (status-announcements is hand-editable
  YAML on the persistent volume). The malformed record is skipped instead of
  the exception propagating.
- `window_days` gained an upper bound (`max: 3650`, in addition to the
  existing `min: 1`) in the Admin Configuration blueprint, since it sizes an
  in-memory array on every page render.

No other findings. Blueprint `permissions:` blocks, the `user-data://`
storage paths, the single `addAllowedDynamicCallable` registration, and the
markdown/`status_sanitize_html` sanitization path were all confirmed correct
as shipped in 0.1.0-0.4.0.

## 0.4.0

- Add the public status page itself: a plugin-provided route
  (`onPagesInitialized` + `Pages::addPage()`, no page file under
  `user/pages`), Twig templates for the overall banner, active/watching
  announcements, each category's N-day strip + uptime, and resolved
  announcements from the last `window_days` days.
- The created page disables Grav's page cache (`cache_enable: false`,
  `never_cache_twig: true`) so the strip and announcement sections never
  freeze on a stale cache.
- Announcement bodies render through Grav's own Markdown pipeline, then a
  new `status_sanitize_html` Twig filter (`rhukster/dom-sanitizer`) before
  reaching the page.
- Add `TimezoneResolver`, `OverallStatus`, `CategoryOrdering`, and
  `AnnouncementSections` -- pure, unit-tested helpers behind the rendering
  layer. `StatusProjector::windowStart()`/`activeInterval()` are now public
  so the rendering layer's "resolved within the window" section reuses the
  exact same window/interval computation the strip itself uses.
- The template extends a configurable `base_template` and degrades to a
  fully self-contained standalone layout when that config is empty. Colors
  are CSS custom properties with built-in fallbacks; the plugin ships no
  host-specific color values.

## 0.3.0

- Add `StatusProjector`: pure, framework-free computation of a category's
  current status, N-day history strip, and uptime percentage from plain
  announcement arrays. Covers interval-overlap severity resolution,
  open-ended-incident handling (an active/watching incident with no
  `ended_at` runs to "now"; a resolved one ends at its own last-modified
  time, never open-ended), and a configurable window length / partial-outage
  weight -- neither hardcoded. Exhaustively unit-tested, including DST
  transitions.
- Add `FlexAnnouncementAdapter`, the thin, duck-typed adapter converting
  `status-announcements` Flex objects into the plain arrays `StatusProjector`
  consumes.

## 0.2.0

- Add the `status-categories` and `status-announcements` Flex Objects
  types, authorable through the Admin panel. Both store one YAML file per
  object under `user/data/flex-objects/`, registered programmatically (no
  `user/config` edits required). `ended_at`-required-when-`resolved` and
  `ended_at >= started_at` are enforced in code on every write path.

## 0.1.0

- Initial scaffold: plugin metadata, default configuration, CI (PHP 8.3 /
  8.4 / 8.5 matrix, PHPUnit, brand-neutrality guard). No user-facing
  behavior yet.
