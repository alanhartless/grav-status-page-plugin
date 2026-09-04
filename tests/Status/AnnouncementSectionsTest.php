<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests\Status;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Plugin\StatusPage\Status\AnnouncementSections;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The public page's announcement sections (ISSUE-205.4 AC): active/watching
 * at the top, resolved-within-the-window at the bottom, newest first. Pure
 * over the same plain keyed-array shape `FlexAnnouncementAdapter` produces --
 * keyed by an opaque string identity so the rendering glue can map a
 * returned key back to its original Flex object without this class ever
 * touching Flex itself.
 */
final class AnnouncementSectionsTest extends TestCase
{
    private const UTC = 'UTC';

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function announcement(array $overrides = []): array
    {
        return array_merge([
            'state' => 'active',
            'severity' => 'outage',
            'categories' => ['api'],
            'started_at' => '2026-09-01 00:00:00',
            'ended_at' => null,
            'updated_at' => '2026-09-01 00:00:00',
        ], $overrides);
    }

    private function tz(string $name = self::UTC): DateTimeZone
    {
        return new DateTimeZone($name);
    }

    private function today(string $value = '2026-09-04 12:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($value, $this->tz());
    }

    // -- activeAndWatchingKeys() --

    #[Test]
    public function includes_active_and_watching_excludes_resolved(): void
    {
        $keyed = [
            'a' => $this->announcement(['state' => 'active']),
            'b' => $this->announcement(['state' => 'watching']),
            'c' => $this->announcement(['state' => 'resolved', 'ended_at' => '2026-09-02 00:00:00']),
        ];

        $keys = AnnouncementSections::activeAndWatchingKeys($keyed);

        sort($keys);
        self::assertSame(['a', 'b'], $keys);
    }

    #[Test]
    public function empty_input_yields_empty_active_and_watching(): void
    {
        self::assertSame([], AnnouncementSections::activeAndWatchingKeys([]));
    }

    #[Test]
    public function active_and_watching_are_sorted_newest_started_first(): void
    {
        $keyed = [
            'older' => $this->announcement(['state' => 'active', 'started_at' => '2026-09-01 00:00:00']),
            'newer' => $this->announcement(['state' => 'watching', 'started_at' => '2026-09-03 00:00:00']),
        ];

        self::assertSame(['newer', 'older'], AnnouncementSections::activeAndWatchingKeys($keyed));
    }

    // -- resolvedWithinWindowKeys() --

    #[Test]
    public function includes_resolved_excludes_active_and_watching(): void
    {
        $keyed = [
            'a' => $this->announcement(['state' => 'active']),
            'b' => $this->announcement(['state' => 'resolved', 'ended_at' => '2026-09-02 00:00:00']),
        ];

        $keys = AnnouncementSections::resolvedWithinWindowKeys($keyed, $this->today(), $this->tz(), 90);

        self::assertSame(['b'], $keys);
    }

    #[Test]
    public function empty_input_yields_empty_resolved_section(): void
    {
        self::assertSame([], AnnouncementSections::resolvedWithinWindowKeys([], $this->today(), $this->tz(), 90));
    }

    #[Test]
    public function excludes_a_resolved_announcement_that_ended_before_the_window(): void
    {
        // windowDays=5 -> window covers 2026-08-31..2026-09-04. This one
        // ended 2026-08-20, well before the window's left edge.
        $keyed = [
            'old' => $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-08-15 00:00:00',
                'ended_at' => '2026-08-20 00:00:00',
            ]),
        ];

        self::assertSame(
            [],
            AnnouncementSections::resolvedWithinWindowKeys($keyed, $this->today(), $this->tz(), 5)
        );
    }

    #[Test]
    public function includes_a_resolved_announcement_that_ended_exactly_at_the_window_start(): void
    {
        // windowDays=5, today=2026-09-04 -> windowStart is 2026-08-31 00:00:00.
        $keyed = [
            'edge' => $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-08-30 00:00:00',
                'ended_at' => '2026-08-31 00:00:00',
            ]),
        ];

        self::assertSame(
            ['edge'],
            AnnouncementSections::resolvedWithinWindowKeys($keyed, $this->today(), $this->tz(), 5)
        );
    }

    #[Test]
    public function a_resolved_announcement_with_null_ended_at_uses_updated_at_never_now(): void
    {
        // D9: a resolved record with no ended_at ends at its own
        // last-modified moment. updated_at here is well before the window,
        // so it must be excluded even though "now" is inside the window.
        $keyed = [
            'stale' => $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-08-01 00:00:00',
                'ended_at' => null,
                'updated_at' => '2026-08-01 00:00:00',
            ]),
        ];

        self::assertSame(
            [],
            AnnouncementSections::resolvedWithinWindowKeys($keyed, $this->today(), $this->tz(), 5)
        );
    }

    #[Test]
    public function resolved_within_window_is_sorted_newest_ended_first(): void
    {
        $keyed = [
            'older' => $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-09-01 00:00:00',
                'ended_at' => '2026-09-01 12:00:00',
            ]),
            'newer' => $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-09-02 00:00:00',
                'ended_at' => '2026-09-03 00:00:00',
            ]),
        ];

        self::assertSame(
            ['newer', 'older'],
            AnnouncementSections::resolvedWithinWindowKeys($keyed, $this->today(), $this->tz(), 90)
        );
    }

    #[Test]
    public function resolved_within_window_derives_its_cutoff_from_the_given_timezone(): void
    {
        // A UTC instant of 2026-09-05 02:00 reads as 2026-09-04 in
        // America/Los_Angeles -- same timezone-changes-the-window-edge case
        // StatusProjector itself is pinned against.
        $today = new DateTimeImmutable('2026-09-05 02:00:00', $this->tz());
        $laTimezone = $this->tz('America/Los_Angeles');

        // windowDays=1 in America/Los_Angeles -> window is exactly
        // 2026-09-04 in that zone. An announcement resolved on 2026-09-03
        // (LA) falls outside a 1-day window.
        $keyed = [
            'yesterday' => $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-09-03 10:00:00',
                'ended_at' => '2026-09-03 12:00:00',
            ]),
        ];

        self::assertSame(
            [],
            AnnouncementSections::resolvedWithinWindowKeys($keyed, $today, $laTimezone, 1)
        );
    }

    // -- Malformed input resilience (ISSUE-205.5 review): status-announcements
    // is hand-editable YAML on disk, so a single unparseable started_at/
    // ended_at must not crash either section for every other announcement. --

    #[Test]
    public function active_and_watching_does_not_throw_on_an_unparseable_started_at(): void
    {
        $keyed = [
            'broken' => $this->announcement(['state' => 'active', 'started_at' => 'not-a-real-date']),
            'fine' => $this->announcement(['state' => 'watching', 'started_at' => '2026-09-03 00:00:00']),
        ];

        $keys = AnnouncementSections::activeAndWatchingKeys($keyed);

        sort($keys);
        self::assertSame(['broken', 'fine'], $keys);
    }

    #[Test]
    public function resolved_within_window_excludes_an_announcement_with_an_unparseable_started_at_instead_of_throwing(): void
    {
        $keyed = [
            'broken' => $this->announcement([
                'state' => 'resolved',
                'started_at' => 'not-a-real-date',
                'ended_at' => '2026-09-02 00:00:00',
            ]),
            'fine' => $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-09-02 00:00:00',
                'ended_at' => '2026-09-03 00:00:00',
            ]),
        ];

        $keys = AnnouncementSections::resolvedWithinWindowKeys($keyed, $this->today(), $this->tz(), 90);

        self::assertSame(['fine'], $keys);
    }

    #[Test]
    public function resolved_within_window_excludes_an_announcement_with_an_unparseable_ended_at_instead_of_throwing(): void
    {
        $keyed = [
            'broken' => $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-09-01 00:00:00',
                'ended_at' => 'not-a-real-date',
            ]),
        ];

        self::assertSame(
            [],
            AnnouncementSections::resolvedWithinWindowKeys($keyed, $this->today(), $this->tz(), 90)
        );
    }
}
