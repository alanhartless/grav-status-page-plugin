<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

use DateTimeZone;
use Exception;

/**
 * Resolves "which timezone decides today" per EPIC-205 decision D10:
 * `plugins.status-page.timezone` -> Grav's `system.timezone` -> UTC, read in
 * exactly one place.
 *
 * `StatusProjector` (ISSUE-205.3) deliberately takes an already-resolved
 * `DateTimeZone` and never touches Grav config itself -- that class's own AC
 * calls this resolution "Grav-coupled config resolution that structurally
 * cannot live in [a] framework-free class" and defers it to this issue's
 * call site. This class is that one place: it stays pure (plain strings in,
 * `DateTimeZone` out) by asking its caller to have already read the two
 * config values, rather than reaching into `Grav::instance()` itself.
 */
final class TimezoneResolver
{
    public static function resolve(?string $configured, ?string $systemTimezone): DateTimeZone
    {
        $candidates = [$configured, $systemTimezone, 'UTC'];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $timezone = self::tryCreate($candidate);
            if ($timezone !== null) {
                return $timezone;
            }
        }

        // Unreachable in practice ('UTC' is always a valid identifier), but
        // keeps the return type honest without a throw for a config typo.
        return new DateTimeZone('UTC');
    }

    private static function tryCreate(string $identifier): ?DateTimeZone
    {
        try {
            return new DateTimeZone($identifier);
        } catch (Exception) {
            return null;
        }
    }
}
