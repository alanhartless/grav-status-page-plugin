<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for a real, confirmed bug: the `categories` field is a
 * `type: select, multiple: true` field driven by a `data-options@` key=>title
 * provider, so its submitted value must validate as `array` (the standard
 * Grav pairing for a keyed multi-select). It was previously declared
 * `type: commalist` -- a validator meant for a plain comma-separated text
 * field -- which caused Admin2 to submit and store the selected categories'
 * *display titles* instead of their machine keys, silently breaking every
 * key-based lookup downstream (the category badge, and StatusProjector's
 * category matching -- an active outage never colored its category).
 *
 * Parsed with a regex over the raw file, matching this repo's existing
 * blueprint-assertion tests (ConfigBlueprintTest, FlexBlueprintPermissionsTest,
 * FlexStoragePathTest) rather than pulling in a YAML-parsing dependency.
 */
final class AnnouncementsBlueprintTest extends TestCase
{
    private const BLUEPRINT_PATH = __DIR__ . '/../blueprints/flex-objects/status-announcements.yaml';

    #[Test]
    public function categories_field_validates_as_array_not_commalist(): void
    {
        $contents = file_get_contents(self::BLUEPRINT_PATH);
        self::assertIsString($contents);

        self::assertMatchesRegularExpression(
            '/categories:.*?\n\s{4}\S+:/s',
            $contents . "\n    end:",
            'categories field not found in status-announcements.yaml.'
        );

        preg_match('/categories:(.*?)\n\s{4}\S+:/s', $contents . "\n    end:", $matches);
        $fieldBlock = $matches[1] ?? '';

        self::assertMatchesRegularExpression(
            '/type:\s*select\b/',
            $fieldBlock,
            'categories must stay a select field.'
        );
        self::assertMatchesRegularExpression(
            '/multiple:\s*true\b/',
            $fieldBlock,
            'categories must stay a multi-select.'
        );
        self::assertMatchesRegularExpression(
            '/validate:\s*\n\s+required:\s*true\s*\n\s+type:\s*array\b/',
            $fieldBlock,
            'categories must validate as type: array -- type: commalist silently stores display titles instead of category keys.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/type:\s*commalist\b/',
            $fieldBlock,
            'categories must never revert to type: commalist -- see this test\'s docblock for the exact failure mode.'
        );
    }
}
