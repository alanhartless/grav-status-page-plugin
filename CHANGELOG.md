# Changelog

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
