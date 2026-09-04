<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage;

use Grav\Common\Grav;

/**
 * Dynamic option provider for the status-announcements blueprint's
 * `categories` field, plus the dangling-category-key lookup helper used
 * when rendering an announcement's category list.
 *
 * `options()` is the actual `data-options@` entry point -- it requires a
 * live Grav instance with the `status-categories` Flex directory registered,
 * so it is exercised by an Admin2/Flex-API round-trip rather than PHPUnit.
 * `formatOptions()` and `resolveTitles()` are pure functions with no Grav
 * dependency and carry the actual test coverage.
 *
 * Registered as the sole allowed dynamic callable via
 * Blueprint::addAllowedDynamicCallable() in
 * StatusPagePlugin::onPluginsInitialized() -- Grav 2.0's dynamic-callable
 * allowlist (GHSA-fj2p-qj2f-74v5) otherwise silently returns no options.
 */
final class CategoryOptions
{
    /**
     * @return array<string, string> key => title, ready for a `select` field's options.
     */
    public static function options(): array
    {
        $flex = Grav::instance()['flex'] ?? null;
        $directory = $flex ? $flex->getDirectory('status-categories') : null;

        if (!$directory) {
            return [];
        }

        $categories = [];
        foreach ($directory->getCollection() as $category) {
            $categories[] = [
                'key' => (string) $category->getKey(),
                'title' => (string) ($category->title ?? $category->getKey()),
                'order' => (int) ($category->order ?? 0),
            ];
        }

        return self::formatOptions($categories);
    }

    /**
     * EXPERIMENTAL: the same categories as `options()`, but flipped to a
     * title => key map -- registered as the `categories` field's actual
     * `data-options@` provider to test a specific theory about Admin2's
     * dynamic-select behavior. Evidence so far: picking a fresh category
     * from the dropdown submits the array's *value* (confirmed -- this is
     * exactly why `CategoryOptions::normalizeToKeys()` had to exist at all,
     * since the submitted value was the title, not the `options()` map's
     * key), and an already-stored *key* value fails to resolve to a label
     * when the edit form hydrates, as if Admin2 never consults the array's
     * key for display at all -- only its value, treated as both the
     * submitted value and the shown label. If that theory holds, flipping
     * to title => key makes the array's value the real key, which should
     * make a fresh pick submit real keys directly (no more reliance on
     * `normalizeToKeys()`), and should make an existing stored key
     * exact-match the option list on hydration. The expected trade-off:
     * the dropdown list and any chip would then display the raw key
     * ("application") instead of the friendly title ("Application")
     * everywhere, since the theory holds Admin2 never displays the array's
     * key. Revert to `options()` if this doesn't hold up under live
     * testing, or if the always-shows-the-key trade-off isn't worth it.
     *
     * @return array<string, string> title => key.
     */
    public static function optionsByTitle(): array
    {
        return array_flip(self::options());
    }

    /**
     * Turns raw category rows into a key=>title options map, ordered by
     * `order` ascending, then `title` to keep output stable for ties.
     *
     * @param array<int, array{key: string, title: string, order?: int}> $categories
     * @return array<string, string>
     */
    public static function formatOptions(array $categories): array
    {
        usort($categories, static function (array $a, array $b): int {
            $orderCompare = ($a['order'] ?? 0) <=> ($b['order'] ?? 0);

            return $orderCompare !== 0 ? $orderCompare : $a['title'] <=> $b['title'];
        });

        $options = [];
        foreach ($categories as $category) {
            $options[$category['key']] = $category['title'];
        }

        return $options;
    }

    /**
     * Resolves a set of stored category keys against a known key=>title map,
     * silently dropping any key that no longer resolves -- e.g. the category
     * was deleted after an announcement referenced it. Used by rendering
     * code so a dangling category reference never fatals the public page.
     *
     * @param string[] $keys
     * @param array<string, string> $titlesByKey
     * @return array<string, string> key => title, dangling keys dropped.
     */
    public static function resolveTitles(array $keys, array $titlesByKey): array
    {
        $resolved = [];
        foreach ($keys as $key) {
            if (isset($titlesByKey[$key])) {
                $resolved[$key] = $titlesByKey[$key];
            }
        }

        return $resolved;
    }

    /**
     * Canonicalizes a submitted `categories` value to machine keys before it
     * reaches validation or storage.
     *
     * Confirmed live: Admin2's dynamic-`data-options@` multi-select submits
     * a category's *display title* (e.g. "AI Features") rather than its key
     * (e.g. "ai-features") on save -- a client-side behavior this class has
     * no visibility into or control over. Rather than leave every downstream
     * key-based lookup (StatusProjector's category matching, the category
     * badge) silently broken, this normalizes at the one point every write
     * path already passes through: a submitted value that's already a valid
     * key passes through unchanged; a value that exactly matches a known
     * category's title (case-insensitive) is translated to that title's
     * key; anything else is left as-is so the field's own `type: array`
     * validation still rejects a genuinely unknown value.
     *
     * @param mixed $submitted
     * @param array<string, string> $titlesByKey key => title
     * @return list<string>
     */
    public static function normalizeToKeys(mixed $submitted, array $titlesByKey): array
    {
        if (!is_array($submitted)) {
            return [];
        }

        $keyByLowerTitle = [];
        foreach ($titlesByKey as $key => $title) {
            $keyByLowerTitle[mb_strtolower($title)] = $key;
        }

        $normalized = [];
        foreach ($submitted as $value) {
            $value = (string) $value;

            if (isset($titlesByKey[$value])) {
                $normalized[] = $value;
                continue;
            }

            $normalized[] = $keyByLowerTitle[mb_strtolower($value)] ?? $value;
        }

        return $normalized;
    }
}
