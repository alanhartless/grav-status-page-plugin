# Changelog

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
