<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `window_days` feeds `StatusProjector::project()`'s `array_fill(0,
 * $windowDays, ...)` (ISSUE-205.5 review) -- it already has a `min: 1` guard,
 * but no upper bound. An operator (accidentally, through the Admin
 * Configuration form, or by hand-editing `user/config/plugins/status-
 * page.yaml`) setting an extreme value would exhaust memory on every render
 * of the public page. This pins a sane upper bound at the blueprint level, in
 * addition to `min: 1`, so the Admin form itself refuses the value rather
 * than only failing at render time.
 *
 * Parsed with a regex over the raw file, matching this repo's existing
 * blueprint-assertion tests (FlexBlueprintPermissionsTest,
 * FlexStoragePathTest) rather than pulling in a YAML-parsing dependency.
 */
final class ConfigBlueprintTest extends TestCase
{
    private const BLUEPRINT_PATH = __DIR__ . '/../blueprints.yaml';

    #[Test]
    public function window_days_field_declares_a_minimum_and_a_maximum(): void
    {
        $contents = file_get_contents(self::BLUEPRINT_PATH);
        self::assertIsString($contents);

        // Isolate the window_days field block (up to the next top-level
        // field or end of the fields list) so `min`/`max` from an unrelated
        // field can't accidentally satisfy this assertion.
        self::assertMatchesRegularExpression(
            '/window_days:.*?\n\s{4}\S+:/s',
            $contents . "\n    end:",
            'window_days field not found in blueprints.yaml.'
        );

        preg_match('/window_days:(.*?)\n\s{4}\S+:/s', $contents . "\n    end:", $matches);
        $fieldBlock = $matches[1] ?? '';

        self::assertMatchesRegularExpression(
            '/min:\s*1\b/',
            $fieldBlock,
            'window_days must keep its min: 1 guard.'
        );
        self::assertMatchesRegularExpression(
            '/max:\s*\d+/',
            $fieldBlock,
            'window_days must declare an upper bound to prevent an operator from configuring a memory-exhausting window.'
        );
    }
}
