<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests;

use Grav\Plugin\StatusPage\CategoryOptions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CategoryOptions has two Grav-free pure helpers plus the actual
 * Grav-integrated `options()` dynamic-callable entry point:
 *
 *  - formatOptions(): turns raw category rows into the key=>title map a
 *    `select` field's data-options@ directive expects, ordered by `order`
 *    then `title`.
 *  - resolveTitles(): resolves a set of stored category keys against a
 *    known key=>title map, silently dropping any key that no longer
 *    resolves -- deleting a referenced category must not fatal rendering,
 *    the dangling key is ignored, not an error.
 *
 * Only the pure helpers are covered here; `options()` itself requires a
 * live $grav['flex'] and is exercised by an Admin2/CLI round-trip instead.
 */
final class CategoryOptionsTest extends TestCase
{
    #[Test]
    public function format_options_maps_key_to_title(): void
    {
        $options = CategoryOptions::formatOptions([
            ['key' => 'api', 'title' => 'API', 'order' => 0],
            ['key' => 'web', 'title' => 'Web app', 'order' => 1],
        ]);

        self::assertSame(['api' => 'API', 'web' => 'Web app'], $options);
    }

    #[Test]
    public function format_options_sorts_by_order_ascending(): void
    {
        $options = CategoryOptions::formatOptions([
            ['key' => 'web', 'title' => 'Web app', 'order' => 5],
            ['key' => 'api', 'title' => 'API', 'order' => 1],
            ['key' => 'sync', 'title' => 'Sync', 'order' => 3],
        ]);

        self::assertSame(['api' => 'API', 'sync' => 'Sync', 'web' => 'Web app'], $options);
        self::assertSame(['api', 'sync', 'web'], array_keys($options));
    }

    #[Test]
    public function format_options_breaks_order_ties_by_title(): void
    {
        $options = CategoryOptions::formatOptions([
            ['key' => 'web', 'title' => 'Web app', 'order' => 0],
            ['key' => 'api', 'title' => 'API', 'order' => 0],
        ]);

        self::assertSame(['api', 'web'], array_keys($options));
    }

    #[Test]
    public function format_options_defaults_missing_order_to_zero(): void
    {
        $options = CategoryOptions::formatOptions([
            ['key' => 'web', 'title' => 'Web app'],
            ['key' => 'api', 'title' => 'API', 'order' => -1],
        ]);

        self::assertSame(['api', 'web'], array_keys($options));
    }

    #[Test]
    public function format_options_returns_empty_array_for_no_categories(): void
    {
        self::assertSame([], CategoryOptions::formatOptions([]));
    }

    #[Test]
    public function resolve_titles_returns_titles_for_known_keys(): void
    {
        $resolved = CategoryOptions::resolveTitles(
            ['api', 'web'],
            ['api' => 'API', 'web' => 'Web app', 'sync' => 'Sync']
        );

        self::assertSame(['api' => 'API', 'web' => 'Web app'], $resolved);
    }

    #[Test]
    public function resolve_titles_silently_drops_a_dangling_key(): void
    {
        // The 'deleted-category' key was referenced by an announcement before
        // the category itself was removed. Rendering must not fatal -- the
        // key is simply omitted from the result.
        $resolved = CategoryOptions::resolveTitles(
            ['api', 'deleted-category'],
            ['api' => 'API']
        );

        self::assertSame(['api' => 'API'], $resolved);
    }

    #[Test]
    public function resolve_titles_returns_empty_array_when_every_key_is_dangling(): void
    {
        $resolved = CategoryOptions::resolveTitles(['deleted-category'], ['api' => 'API']);

        self::assertSame([], $resolved);
    }

    #[Test]
    public function resolve_titles_returns_empty_array_for_no_keys(): void
    {
        self::assertSame([], CategoryOptions::resolveTitles([], ['api' => 'API']));
    }
}
