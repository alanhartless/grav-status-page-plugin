# Status Page plugin for Grav

A public, [Statuspage.io](https://www.atlassian.com/software/statuspage)-style
operational status page for [Grav CMS](https://getgrav.org): operators post
announcements scoped to one or more categories (active, watching, or
resolved), and each category shows its current operational state plus a
rolling daily history strip with an aggregate uptime percentage.

Built entirely on Grav's file-based [Flex Objects](https://learn.getgrav.org/17/flex-objects)
framework — no database required.

**Status:** the public page is live. Operators author categories and
announcements through the Admin panel; the plugin computes each category's
current status, daily history strip, and uptime % (exhaustively unit-tested,
`classes/Status/StatusProjector.php`) and serves it at a plugin-provided
route -- no page file to add, no theme required. See `CHANGELOG.md`.

## Requirements

- Grav `>=2.0.0`
- The [Flex Objects](https://github.com/trilbymedia/grav-plugin-flex-objects)
  plugin `>=1.4.0` (for Admin-panel authoring of categories and
  announcements)
- PHP `>=8.3`

## Installation

The recommended way to install this plugin is via [GPM](https://learn.getgrav.org/17/plugins/plugin-tutorial#installing-via-gpm-gravpackagemanager):

```
bin/gpm install status-page
```

You can also install it manually by cloning or downloading this repository
into your `user/plugins/status-page` directory.

## Configuration

Copy `user/plugins/status-page/status-page.yaml` to
`user/config/plugins/status-page.yaml` and edit it, or use the Admin panel
(Plugins → Status Page → Configuration) — no code change or restart is
needed for any of these to take effect.

| Key | Default | What it controls |
|---|---|---|
| `enabled` | `true` | Whether the plugin is active. |
| `route` | `/status` | The URL path the public status page is served at. |
| `page_title` | `Status` | The page's `<title>` and on-page heading text. |
| `window_days` | `90` | Length of the daily history strip, and how far back resolved announcements are shown. |
| `uptime_partial_weight` | `0.5` | How much a partial-outage day costs against the uptime percentage (`0` = doesn't count, `1` = counts as a full outage day). |
| `base_template` | *(empty)* | An optional theme Twig partial to extend for header/footer chrome. Leave empty for a self-contained standalone page. |
| `timezone` | *(empty)* | An explicit PHP timezone identifier. Leave empty to use Grav's own `system.timezone`, falling back to UTC. |

## Data model

Two Flex Objects types, authored through the Admin panel (Flex Objects
plugin required). Both store one YAML file per object under `user/data/`,
which is safe to back up and diff, and is never touched by a plugin update.

### Status Categories

The service categories the status page groups announcements under. A
category has no status field of its own -- its current status is always
derived from its announcements.

| Field | Type | Required | Notes |
|---|---|---|---|
| `key` | text | yes | Machine name. Lowercase letters, numbers, and hyphens only. Also the filename, so it must be unique -- creating a second category with an already-used key overwrites the same file rather than creating a duplicate. |
| `title` | text | yes | Display name. |
| `description` | textarea | no | |
| `order` | number | no | Lower numbers are listed first. Defaults to `0`. |

### Status Announcements

Incident, maintenance, and informational posts, scoped to one or more
categories.

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | text | yes | |
| `body` | markdown | no | |
| `state` | select: `active` / `watching` / `resolved` | yes | Defaults to `active`. |
| `severity` | select: `none` / `partial-outage` / `outage` | yes | Defaults to `none`. `none` is a deliberate, valid choice for a maintenance notice or informational post -- it colors no day on the history strip. Only `partial-outage` and `outage` count against uptime. |
| `categories` | multi-select | yes, at least one | Options are populated dynamically from the categories that currently exist. If a referenced category is later deleted, the dangling reference is silently ignored wherever it's rendered rather than causing an error. |
| `started_at` | datetime | yes | |
| `ended_at` | datetime | no | Required once `state` is `resolved`. Must not be earlier than `started_at`. Both rules are enforced in code, not only in the Admin form, so they hold for any write path. |

## Uptime calculation

Uptime over the configured window is:

```
(window_days − outage_days − uptime_partial_weight × partial_outage_days) / window_days
```

At the shipped defaults (`window_days: 90`, `uptime_partial_weight: 0.5`),
a full outage day costs a full day of uptime and a partial-outage day costs
half a day.

## The public page

Served at `route` (default `/status`) as a plugin-provided page -- no page
file to create under `user/pages`, and no theme dependency. Page structure,
top to bottom: an overall banner, active/watching announcements, each
category with its current status, N-day strip, and uptime %, then resolved
announcements from the last `window_days` days (newest first, older ones
simply don't render -- there is no archive or pagination).

**Overriding templates.** This plugin's own `templates/` folder registers
three files: `status-page.html.twig` (the page shell), `partials/status-
content.html.twig` (the actual content, reused by both layouts below), and
the `partials/status-strip.html.twig` / `partials/status-announcement.html.twig`
partials it includes. Grav's normal template-override rules apply: a theme
(or another plugin loaded after this one) can ship a file at the same
relative path under its own `templates/` folder to override any of them.

**Header/footer chrome.** Set `base_template` to a Twig template name your
theme already defines (e.g. `partials/base.html.twig`) that renders a
`{% block content %}{% endblock %}` -- the status page extends it and fills
that block. Leave `base_template` empty (the default) to render this
plugin's own `partials/standalone-base.html.twig`, a minimal, fully
self-contained `<html>` page with no theme dependency at all.

**Colors.** The page's status colors are CSS custom properties with
built-in fallbacks (`var(--status-operational, #2e7d32)`, `--status-
partial-outage`, `--status-outage`, plus `--status-text`/`--status-muted`/
`--status-border`/`--status-background`), all defined in this plugin's own
`css/status-page.css`. A host theme overrides any of them the ordinary CSS
way -- set the same custom property names in its own stylesheet, scoped as
broadly or as narrowly as it likes.

## License

[MIT](LICENSE)
