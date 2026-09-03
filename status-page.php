<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;

/**
 * Status Page plugin for Grav.
 *
 * Renders a public, Statuspage.io-style operational status page (categories +
 * incident announcements + a rolling daily history strip) from Flex Objects
 * data authored through Grav's Admin panel. See README.md for setup.
 *
 * This class is intentionally a stub for now -- it registers no event
 * listeners and defines no behavior. It exists so the plugin loads cleanly
 * as soon as it is installed; the Flex data model, status projection, and
 * public route land in later releases of this plugin.
 */
class StatusPagePlugin extends Plugin
{
    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * Placeholder initialization hook. Deliberately empty until the Flex
     * data model and route registration land.
     *
     * @return void
     */
    public function onPluginsInitialized()
    {
    }
}
