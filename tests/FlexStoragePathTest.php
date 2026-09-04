<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the single highest-risk mistake in this epic: every Flex storage path
 * MUST resolve under `user-data://` and nothing else (EPIC-205 ISSUE-205.2).
 *
 * `user/data` is the one part of the production marketing-site container
 * that lives on the persistent volume; `user/plugins` (which is what
 * `plugin://` resolves to) is image-baked and destroyed on every redeploy.
 * A `plugin://` storage path would lose every category and every incident
 * record, silently, on the very next deploy.
 *
 * This test parses the actual blueprint YAML that ships with the plugin
 * (not a copy), so a future edit that flips the storage folder back to
 * `plugin://` -- or to anything other than `user-data://` -- fails this
 * test immediately, without needing a Grav bootstrap.
 */
final class FlexStoragePathTest extends TestCase
{
    private const BLUEPRINTS_DIR = __DIR__ . '/../blueprints/flex-objects';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function blueprintProvider(): array
    {
        return [
            'status-categories' => ['status-categories.yaml', 'user-data://flex-objects/status-categories'],
            'status-announcements' => ['status-announcements.yaml', 'user-data://flex-objects/status-announcements'],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blueprintFilenameProvider(): array
    {
        return [
            'status-categories' => ['status-categories.yaml'],
            'status-announcements' => ['status-announcements.yaml'],
        ];
    }

    #[Test]
    #[DataProvider('blueprintProvider')]
    public function storage_folder_is_exactly_the_expected_user_data_path(string $filename, string $expectedFolder): void
    {
        $contents = $this->readBlueprint($filename);

        self::assertMatchesRegularExpression(
            '/folder:\s*[\'"]' . preg_quote($expectedFolder, '/') . '[\'"]/',
            $contents,
            "Expected {$filename} to declare storage folder '{$expectedFolder}'."
        );
    }

    #[Test]
    #[DataProvider('blueprintFilenameProvider')]
    public function storage_folder_never_uses_the_plugin_stream(string $filename): void
    {
        $contents = $this->readBlueprint($filename);

        self::assertDoesNotMatchRegularExpression(
            '/folder:\s*[\'"]plugin:\/\//',
            $contents,
            "{$filename} must never store Flex data under plugin:// -- it does not survive a redeploy."
        );
    }

    private function readBlueprint(string $filename): string
    {
        $path = self::BLUEPRINTS_DIR . '/' . $filename;

        self::assertFileExists($path, "Blueprint {$filename} is missing.");

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }
}
