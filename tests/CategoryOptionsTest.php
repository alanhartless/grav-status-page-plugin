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

    // -- normalizeToKeys(): confirmed live (2026-09-04) that Admin2's
    // dynamic-`data-options@` multi-select submits a category's display
    // title instead of its key on save. These pin the canonicalization that
    // makes every downstream key-based lookup work regardless. --

    #[Test]
    public function normalize_to_keys_passes_through_an_already_correct_key(): void
    {
        $result = CategoryOptions::normalizeToKeys(['ai-features'], ['ai-features' => 'AI Features']);

        self::assertSame(['ai-features'], $result);
    }

    #[Test]
    public function normalize_to_keys_translates_a_submitted_title_to_its_key(): void
    {
        $result = CategoryOptions::normalizeToKeys(['AI Features'], ['ai-features' => 'AI Features']);

        self::assertSame(['ai-features'], $result);
    }

    #[Test]
    public function normalize_to_keys_title_match_is_case_insensitive(): void
    {
        $result = CategoryOptions::normalizeToKeys(['ai features'], ['ai-features' => 'AI Features']);

        self::assertSame(['ai-features'], $result);
    }

    #[Test]
    public function normalize_to_keys_handles_a_mix_of_keys_and_titles(): void
    {
        $result = CategoryOptions::normalizeToKeys(
            ['ai-features', 'Applicaton'],
            ['ai-features' => 'AI Features', 'applicaton' => 'Applicaton']
        );

        self::assertSame(['ai-features', 'applicaton'], $result);
    }

    #[Test]
    public function normalize_to_keys_leaves_a_genuinely_unknown_value_unchanged(): void
    {
        // Left as-is deliberately -- the field's own `type: array` validation
        // is what rejects a truly unknown value; this method's job is only
        // to canonicalize values that DO correspond to a real category.
        $result = CategoryOptions::normalizeToKeys(['not-a-real-category'], ['ai-features' => 'AI Features']);

        self::assertSame(['not-a-real-category'], $result);
    }

    #[Test]
    public function normalize_to_keys_returns_empty_array_for_a_non_array_submission(): void
    {
        self::assertSame([], CategoryOptions::normalizeToKeys(null, ['ai-features' => 'AI Features']));
        self::assertSame([], CategoryOptions::normalizeToKeys('AI Features', ['ai-features' => 'AI Features']));
    }
}
