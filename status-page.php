<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Data\Blueprint;
use Grav\Common\Plugin;
use Grav\Events\FlexRegisterEvent;
use Grav\Framework\Flex\Flex;
use Grav\Plugin\StatusPage\CategoryOptions;

/**
 * Status Page plugin for Grav.
 *
 * Renders a public, Statuspage.io-style operational status page (categories +
 * incident announcements + a rolling daily history strip) from Flex Objects
 * data authored through Grav's Admin panel. See README.md for setup.
 *
 * As of ISSUE-205.2, this registers the two Flex data types the rest of the
 * plugin builds on: `status-categories` and `status-announcements`. The
 * status projection and the public route land in later issues.
 */
class StatusPagePlugin extends Plugin
{
    /**
     * Exposes this plugin's own `blueprints/` folder on the `blueprints://`
     * stream (`Grav\Common\Plugins::setup()` only adds a plugin's blueprint
     * path when it declares this feature -- without it,
     * `blueprints://flex-objects/status-categories.yaml` resolves to
     * nothing and Flex reports the blueprint file as missing). Same
     * declaration `flex-objects` itself uses.
     *
     * @var array
     */
    public $features = [
        'blueprints' => 100,
    ];

    /**
     * The sole `Class::method` dynamic-data provider this plugin uses for a
     * blueprint `data-options@` directive. Kept as one constant so it is
     * obvious at a glance that exactly one callable is registered -- a
     * broader registration is a security finding (EPIC-205 ISSUE-205.2 AC).
     */
    private const CATEGORY_OPTIONS_CALLABLE = CategoryOptions::class . '::options';

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            FlexRegisterEvent::class => ['onRegisterFlex', 0],
        ];
    }

    /**
     * Registers a PSR-4 autoloader for this plugin's own `classes/` tree
     * (`Grav\Plugin\StatusPage\` -> `classes/`, matching composer.json).
     *
     * `Grav\Common\Plugins::loadPlugin()` calls this automatically on every
     * plugin that defines it -- see flex-objects' own `autoload()` for the
     * same pattern. Deliberately does NOT `require __DIR__ . '/vendor/autoload.php'`
     * the way flex-objects does: this plugin's composer.json has no runtime
     * dependencies (only phpunit, dev-only), and `/vendor/` is gitignored, so
     * nothing installs it in the production image. Registering the PSR-4
     * mapping directly against a fresh ClassLoader needs no vendor/ directory
     * at all -- `Composer\Autoload\ClassLoader` is already available process-
     * wide via Grav core's own autoloader.
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        $loader = new ClassLoader();
        $loader->addPsr4('Grav\\Plugin\\StatusPage\\', __DIR__ . '/classes');
        $loader->register();

        return $loader;
    }

    /**
     * Registers the `categories` field's dynamic option provider against
     * Grav 2.0's dynamic-callable allowlist (GHSA-fj2p-qj2f-74v5). Without
     * this call, `CategoryOptions::options()` would silently return no
     * options -- the callable is refused, not errored.
     *
     * @return void
     */
    public function onPluginsInitialized(): void
    {
        Blueprint::addAllowedDynamicCallable(self::CATEGORY_OPTIONS_CALLABLE);
    }

    /**
     * Registers `status-categories` and `status-announcements` as Flex
     * directory types programmatically. This must never be replaced with a
     * hand-edited `user/config/plugins/flex-objects.yaml` entry: `user/config`
     * is seed-once on the production persistent volume, so a committed
     * config change never reaches an already-deployed production install
     * (EPIC-205 ISSUE-205.2 AC).
     *
     * @param FlexRegisterEvent $event
     * @return void
     */
    public function onRegisterFlex(FlexRegisterEvent $event): void
    {
        /** @var Flex $flex */
        $flex = $event->flex;

        $types = [
            'status-categories' => 'blueprints://flex-objects/status-categories.yaml',
            'status-announcements' => 'blueprints://flex-objects/status-announcements.yaml',
        ];

        foreach ($types as $type => $blueprint) {
            if (!$flex->hasDirectory($type)) {
                $flex->addDirectoryType($type, $blueprint);
            }
        }
    }
}
