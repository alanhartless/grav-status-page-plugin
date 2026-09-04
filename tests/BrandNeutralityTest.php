<?php

namespace Grav\Plugin\StatusPage\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Brand-neutrality guard (EPIC-205 ISSUE-205.1, decision D12).
 *
 * This plugin is published as a generic, reusable Grav plugin, not tied to
 * any one product. This test fails on a case-insensitive match for any of
 * the banned terms anywhere in the repository's tracked file tree.
 *
 * This test file itself is excluded from the scan -- it necessarily names
 * the banned terms as string literals in order to check for them, and that
 * is not a real violation.
 */
final class BrandNeutralityTest extends TestCase
{
    private const BANNED_TERMS = [
        'wrytersdesk',
        'wryters desk',
        'wrytersdesk.com',
    ];

    /** Path of this file, relative to the repo root, as reported by `git ls-files`. */
    private const SELF_PATH = 'tests/BrandNeutralityTest.php';

    public function testNoTrackedFileContainsBrandedTerms(): void
    {
        $root = \dirname(__DIR__);

        $output = [];
        $exitCode = 0;
        \exec('git -C ' . \escapeshellarg($root) . ' ls-files 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, 'git ls-files must succeed to run this guard: ' . \implode("\n", $output));
        self::assertNotEmpty($output, 'git ls-files returned no tracked files -- this guard cannot verify anything.');

        $violations = [];

        foreach ($output as $relativePath) {
            if ($relativePath === self::SELF_PATH) {
                continue;
            }

            $fullPath = $root . '/' . $relativePath;

            if (!\is_file($fullPath)) {
                continue;
            }

            $contents = \file_get_contents($fullPath);

            if ($contents === false) {
                continue;
            }

            foreach (self::BANNED_TERMS as $term) {
                if (\stripos($contents, $term) !== false) {
                    $violations[] = \sprintf('%s contains banned term "%s"', $relativePath, $term);
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Found product-specific branding in a public, generic plugin repo:\n" . \implode("\n", $violations)
        );
    }
}
