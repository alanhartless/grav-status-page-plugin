<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests\Status;

use DateTimeZone;
use Grav\Plugin\StatusPage\Status\TimezoneResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure resolution of "which timezone decides today": plugin config ->
 * Grav's system.timezone -> UTC, read in exactly one place.
 *
 * This is the one piece of Grav-coupled config reading `StatusProjector`
 * deliberately defers to its caller. Kept pure by taking the two
 * already-read config strings as plain arguments rather than touching
 * Grav\Common\Grav::instance() itself -- the plugin's onTwigPageVariables
 * call site does that one read and passes the strings in.
 */
final class TimezoneResolverTest extends TestCase
{
    #[Test]
    public function uses_the_configured_timezone_when_set(): void
    {
        $tz = TimezoneResolver::resolve('America/New_York', 'UTC');

        self::assertInstanceOf(DateTimeZone::class, $tz);
        self::assertSame('America/New_York', $tz->getName());
    }

    #[Test]
    public function falls_back_to_the_system_timezone_when_configured_is_empty_string(): void
    {
        $tz = TimezoneResolver::resolve('', 'America/Chicago');

        self::assertSame('America/Chicago', $tz->getName());
    }

    #[Test]
    public function falls_back_to_the_system_timezone_when_configured_is_null(): void
    {
        $tz = TimezoneResolver::resolve(null, 'America/Chicago');

        self::assertSame('America/Chicago', $tz->getName());
    }

    #[Test]
    public function falls_back_to_utc_when_both_are_empty(): void
    {
        $tz = TimezoneResolver::resolve('', '');

        self::assertSame('UTC', $tz->getName());
    }

    #[Test]
    public function falls_back_to_utc_when_both_are_null(): void
    {
        $tz = TimezoneResolver::resolve(null, null);

        self::assertSame('UTC', $tz->getName());
    }

    #[Test]
    public function configured_timezone_wins_over_a_present_system_timezone(): void
    {
        $tz = TimezoneResolver::resolve('Asia/Tokyo', 'America/Chicago');

        self::assertSame('Asia/Tokyo', $tz->getName());
    }

    #[Test]
    public function an_invalid_configured_identifier_falls_back_to_utc_rather_than_throwing(): void
    {
        $tz = TimezoneResolver::resolve('Not/A/Real/Zone', 'UTC');

        self::assertSame('UTC', $tz->getName());
    }

    #[Test]
    public function an_invalid_system_timezone_falls_back_to_utc_rather_than_throwing(): void
    {
        $tz = TimezoneResolver::resolve(null, 'Not/A/Real/Zone');

        self::assertSame('UTC', $tz->getName());
    }
}
