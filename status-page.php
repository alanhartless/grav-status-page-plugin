<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use DateTimeImmutable;
use Grav\Common\Data\Blueprint;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Page\Page;
use Grav\Common\Page\Pages;
use Grav\Common\Plugin;
use Grav\Events\FlexRegisterEvent;
use Grav\Framework\Flex\Flex;
use Grav\Plugin\StatusPage\CategoryOptions;
use Grav\Plugin\StatusPage\Status\AnnouncementBodyRenderer;
use Grav\Plugin\StatusPage\Status\StatusPagePresenter;
use RocketTheme\Toolbox\Event\Event;
use SplFileInfo;
use Twig\TwigFilter;

/**
 * Status Page plugin for Grav.
 *
 * Renders a public, Statuspage.io-style operational status page (categories +
 * incident announcements + a rolling daily history strip) from Flex Objects
 * data authored through Grav's Admin panel. See README.md for setup.
 *
 * Registers the two Flex data types the rest of the plugin builds on --
 * `status-categories` and `status-announcements` -- and the public route
 * that renders them.
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
     * broader registration would be a real security concern (Grav 2.0's
     * dynamic-callable allowlist exists for exactly this reason).
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
            'onPagesInitialized' => ['onPagesInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onTwigInitialized' => ['onTwigInitialized', 0],
            'onTwigPageVariables' => ['onTwigPageVariables', 0],
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
     * hand-edited `user/config/plugins/flex-objects.yaml` entry: on hosts
     * where `user/config` is seeded once onto a persistent volume, a
     * committed config change never reaches an already-deployed
     * installation.
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

    /**
     * Registers the public status page as a plugin-provided route, never a
     * page file under `user/pages`. On hosts where that directory is
     * seeded once onto a persistent volume, a committed page file would
     * never reach an already-deployed installation without a manual
     * operator step -- a plugin-provided route ships automatically
     * instead. `login` plugin's own `Login::addPage()`
     * (`user/plugins/login/classes/Login.php`) is the working in-tree
     * precedent this mirrors.
     *
     * The backing .md file lives in this plugin's OWN `pages/` directory
     * (`plugin://status-page/pages/status-page.md`), not under `user/pages`
     * -- image-baked the same way the rest of the plugin is, and required
     * by `PageInterface::init()`'s own `SplFileInfo` argument. Its
     * frontmatter is a placeholder; `page_title`, `cache_enable`, and
     * `never_cache_twig` are all set from live plugin config below rather
     * than hardcoded in that file, so an operator can change them without
     * a code change.
     *
     * Runs on every request (matching `login`'s own pattern) -- constructing
     * one in-memory Page object is cheap, and `Pages::find()` below means an
     * existing real content page at the same route is never shadowed.
     */
    public function onPagesInitialized(): void
    {
        $route = (string) $this->config->get('plugins.status-page.route', '/status');

        /** @var Pages $pages */
        $pages = $this->grav['pages'];

        if ($pages->find($route) instanceof PageInterface) {
            // A real content page already claims this route -- never shadow it.
            return;
        }

        $page = new Page();
        $page->init(new SplFileInfo('plugin://status-page/pages/status-page.md'));
        $page->route($route);
        $page->slug(basename($route) ?: 'status');

        $page->modifyHeader('title', (string) $this->config->get('plugins.status-page.page_title', 'Status'));
        // Grav's page cache invalidates on the page file's mtime, not on a
        // Flex save or the calendar rolling over a day -- without this the
        // strip and the announcement sections freeze at whatever they
        // rendered when the cache was last warmed.
        $page->modifyHeader('cache_enable', false);
        $page->modifyHeader('never_cache_twig', true);
        // The two flags above only stop Grav's own SERVER-SIDE render cache
        // from reusing a stale copy -- they say nothing about what the
        // BROWSER does. Without an explicit override, Grav core's default
        // system.pages.expires (604800 seconds -- 7 days) still applies,
        // sending Cache-Control: max-age=604800 on every response. A
        // browser honoring that header serves its own week-old cached copy
        // and never even asks the server again, so the server always
        // rendering fresh data is irrelevant -- confirmed as the actual
        // cause of needing a manual admin cache-clear (which does nothing
        // for browser-side caching) plus a hard-refresh to see a saved
        // announcement change. `no-store` tells every cache in the chain
        // (browser and any intermediate proxy) never to store this
        // response at all.
        //
        // Deliberately NOT modifyHeader('expires'/'cache_control', ...):
        // Page::expires()/cacheControl() read their own dedicated
        // properties ($this->expires/$this->cache_control), which are only
        // ever synced from $this->header once, during the page's initial
        // frontmatter parse -- before these lines run. modifyHeader() only
        // touches the header object, so it would silently do nothing here;
        // confirmed live (the response still carried max-age=604800 with
        // modifyHeader()). The dedicated setters are the only way in.
        $page->expires(0);
        $page->cacheControl('no-store');

        $pages->addPage($page, $route);
    }

    /**
     * Registers this plugin's own `templates/` folder on the Twig loader,
     * matching every other Grav plugin's own `onTwigTemplatePaths` pattern
     * (e.g. `login`, `shortcode-core`).
     */
    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Registers the `status_sanitize_html` Twig filter used by the
     * announcement templates to sanitize markdown-rendered bodies, so a
     * `<script>` tag (or any other injected markup) in an authored body
     * never executes. Marked `is_safe: ['html']` -- same as core's own
     * `|markdown` filter that feeds it -- so Twig does not re-escape
     * output this class has already made safe.
     */
    public function onTwigInitialized(): void
    {
        $this->grav['twig']->twig()->addFilter(
            new TwigFilter('status_sanitize_html', [AnnouncementBodyRenderer::class, 'sanitize'], ['is_safe' => ['html']])
        );
    }

    /**
     * Injects this plugin's page data into Twig only when the page actually
     * being rendered is the status page -- every other page load on the
     * site pays nothing beyond the one route-string comparison. This is
     * also the plugin's one call site for `system.timezone` (D10's "read in
     * exactly one place") and for enqueuing the plugin's own default CSS.
     */
    public function onTwigPageVariables(Event $event): void
    {
        $page = $event['page'] ?? null;
        if (!$page instanceof PageInterface) {
            return;
        }

        $route = (string) $this->config->get('plugins.status-page.route', '/status');
        if ($page->route() !== $route) {
            return;
        }

        /** @var Flex $flex */
        $flex = $this->grav['flex'];

        $this->grav['twig']->twig_vars['status'] = StatusPagePresenter::build(
            $flex,
            $this->config,
            new DateTimeImmutable('now')
        );

        // This plugin ships no host-specific color values -- only CSS
        // custom properties with built-in fallbacks (see css/status-page.css).
        // A host theme overrides them the ordinary CSS way, in its own
        // stylesheet.
        $this->grav['assets']->addCss('plugin://status-page/css/status-page.css');

        // Every timestamp is rendered server-side as its stored UTC value
        // (a <time data-status-time> element, see partials/status-time.html.twig)
        // -- this reformats each one to the visitor's own browser-local
        // time on load. A public status page has no authenticated visitor
        // whose timezone preference the server could otherwise know, unlike
        // the admin-side plugins.status-page.timezone setting, which is
        // deliberately a different, site-operator-facing concept (see
        // StatusAnnouncementObject's class docblock).
        $this->grav['assets']->addJs('plugin://status-page/js/status-page.js', ['group' => 'bottom']);
    }
}
