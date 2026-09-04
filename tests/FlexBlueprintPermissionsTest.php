<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * flex-objects >= 1.4.3 denies admin access to a Flex directory by default
 * unless its blueprint carries an explicit `config.admin.permissions` block.
 * A missing block makes the type unauthorable with no obvious error
 * message, so this is pinned mechanically rather than left to review
 * vigilance.
 */
final class FlexBlueprintPermissionsTest extends TestCase
{
    private const BLUEPRINTS_DIR = __DIR__ . '/../blueprints/flex-objects';

    /**
     * @return array<string, array{0: string}>
     */
    public static function blueprintProvider(): array
    {
        return [
            'status-categories' => ['status-categories.yaml'],
            'status-announcements' => ['status-announcements.yaml'],
        ];
    }

    #[Test]
    #[DataProvider('blueprintProvider')]
    public function blueprint_declares_an_explicit_admin_permissions_block(string $filename): void
    {
        $path = self::BLUEPRINTS_DIR . '/' . $filename;
        self::assertFileExists($path);

        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertMatchesRegularExpression(
            '/^\s{4}permissions:\s*$/m',
            $contents,
            "{$filename} must declare an explicit config.admin.permissions block, or flex-objects denies admin access with no obvious error."
        );
    }
}
