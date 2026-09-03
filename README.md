# Status Page plugin for Grav

A public, [Statuspage.io](https://www.atlassian.com/software/statuspage)-style
operational status page for [Grav CMS](https://getgrav.org): operators post
announcements scoped to one or more categories (active, watching, or
resolved), and each category shows its current operational state plus a
rolling daily history strip with an aggregate uptime percentage.

Built entirely on Grav's file-based [Flex Objects](https://learn.getgrav.org/17/flex-objects)
framework — no database required.

**Status:** early scaffold. This release lays the groundwork (plugin
metadata, configuration, CI) that later releases build the actual data
model, status computation, and public page on top of. See `CHANGELOG.md`.

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

## Uptime calculation

Uptime over the configured window is:

```
(window_days − outage_days − uptime_partial_weight × partial_outage_days) / window_days
```

At the shipped defaults (`window_days: 90`, `uptime_partial_weight: 0.5`),
a full outage day costs a full day of uptime and a partial-outage day costs
half a day.

## License

[MIT](LICENSE)
