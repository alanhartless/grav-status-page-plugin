<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests\Status;

use DateTimeImmutable;
use DateTimeZone;
use Grav\Plugin\StatusPage\Status\StatusProjection;
use Grav\Plugin\StatusPage\Status\StatusProjector;
use InvalidArgumentException;
use Iterator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * StatusProjector is EPIC-205's only genuinely error-prone logic (interval
 * overlap, open-ended-incident handling, day-boundary/DST arithmetic), so
 * this suite intentionally carries the bulk of the epic's test coverage
 * (ISSUE-205.3 AC). Every boundary scenario named in the issue file has its
 * own dedicated test rather than being folded into a bigger "happy path"
 * assertion, so a future regression points at the exact rule that broke.
 *
 * The projector is pure PHP -- no Grav bootstrap, no filesystem, no Flex.
 * Announcements are plain arrays with the shape:
 *   state:       'active'|'watching'|'resolved'
 *   severity:    'none'|'partial-outage'|'outage'
 *   categories:  list<string>
 *   started_at:  string (parseable by DateTimeImmutable) | int (unix ts)
 *   ended_at:    string|int|null
 *   updated_at:  string|int -- the announcement's own last-modified moment,
 *                required whenever a 'resolved' announcement's ended_at is
 *                null (D9: never open-ended for a resolved record).
 */
final class StatusProjectorTest extends TestCase
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
            'started_at' => '2026-01-01 00:00:00',
            'ended_at' => null,
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    private function tz(string $name = self::UTC): DateTimeZone
    {
        return new DateTimeZone($name);
    }

    private function today(string $value = '2026-09-04 12:00:00', string $tz = self::UTC): DateTimeImmutable
    {
        return new DateTimeImmutable($value, $this->tz($tz));
    }

    // -- Empty input / defaults (D8, AC "empty list -> operational/100%") --

    #[Test]
    public function empty_announcement_list_yields_all_operational_days_and_full_uptime(): void
    {
        $projection = StatusProjector::project(
            [],
            'api',
            $this->today(),
            $this->tz(),
            90,
            0.5
        );

        self::assertSame('operational', $projection->current);
        self::assertCount(90, $projection->days);
        foreach ($projection->days as $day) {
            self::assertSame('operational', $day['level']);
        }
        self::assertSame(1.0, $projection->uptime);
    }

    #[Test]
    public function announcements_for_a_different_category_leave_this_category_fully_operational(): void
    {
        // Also stands in for D8: the projector has no notion of "when a
        // category was created" at all, so days before a category existed
        // are indistinguishable from any other day with no matching
        // announcement -- both are simply operational.
        $announcements = [
            $this->announcement(['categories' => ['web'], 'severity' => 'outage']),
        ];

        $projection = StatusProjector::project($announcements, 'api', $this->today(), $this->tz(), 30, 0.5);

        foreach ($projection->days as $day) {
            self::assertSame('operational', $day['level']);
        }
        self::assertSame(1.0, $projection->uptime);
    }

    #[Test]
    public function severity_none_never_colors_a_day(): void
    {
        $announcements = [
            $this->announcement([
                'severity' => 'none',
                'started_at' => '2026-09-04 00:00:00',
                'ended_at' => '2026-09-04 23:59:59',
            ]),
        ];

        $projection = StatusProjector::project($announcements, 'api', $this->today(), $this->tz(), 5, 0.5);

        foreach ($projection->days as $day) {
            self::assertSame('operational', $day['level']);
        }
    }

    // -- Window shape --

    #[Test]
    public function window_spans_exactly_window_days_ending_today_inclusive(): void
    {
        $projection = StatusProjector::project([], 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 5, 0.5);

        $dates = array_column($projection->days, 'date');

        self::assertSame(
            ['2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'],
            $dates
        );
    }

    #[Test]
    public function window_days_of_one_yields_a_single_entry_for_today(): void
    {
        $projection = StatusProjector::project([], 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 1, 0.5);

        self::assertCount(1, $projection->days);
        self::assertSame('2026-09-04', $projection->days[0]['date']);
    }

    #[Test]
    public function window_days_is_not_hardcoded_to_ninety(): void
    {
        $projection = StatusProjector::project([], 'api', $this->today(), $this->tz(), 30, 0.5);

        self::assertCount(30, $projection->days);
    }

    #[Test]
    public function rejects_a_window_days_below_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StatusProjector::project([], 'api', $this->today(), $this->tz(), 0, 0.5);
    }

    // -- Timezone: read once, actually used to decide "today" --

    #[Test]
    public function timezone_determines_which_calendar_day_is_today(): void
    {
        // 2026-09-04 02:00 UTC is still 2026-09-03 in America/Los_Angeles (UTC-7).
        $instant = new DateTimeImmutable('2026-09-04 02:00:00', new DateTimeZone('UTC'));

        $utc = StatusProjector::project([], 'api', $instant, new DateTimeZone('UTC'), 3, 0.5);
        $pacific = StatusProjector::project([], 'api', $instant, new DateTimeZone('America/Los_Angeles'), 3, 0.5);

        self::assertSame('2026-09-04', $utc->days[array_key_last($utc->days)]['date']);
        self::assertSame('2026-09-03', $pacific->days[array_key_last($pacific->days)]['date']);
    }

    // -- Boundary tests named explicitly in the issue file --

    #[Test]
    public function announcement_entirely_before_the_window_contributes_nothing(): void
    {
        $announcements = [
            $this->announcement([
                'started_at' => '2026-08-01 00:00:00',
                'ended_at' => '2026-08-02 00:00:00',
            ]),
        ];

        $projection = StatusProjector::project($announcements, 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 5, 0.5);

        foreach ($projection->days as $day) {
            self::assertSame('operational', $day['level']);
        }
        self::assertSame(1.0, $projection->uptime);
    }

    #[Test]
    public function announcement_straddling_the_windows_left_edge_colors_only_in_window_days(): void
    {
        // Window (windowDays=5, today=2026-09-04) is 08-31..09-04.
        // Incident runs 08-29 -> 09-01, so only 08-31 and 09-01 are in window.
        $announcements = [
            $this->announcement([
                'started_at' => '2026-08-29 00:00:00',
                'ended_at' => '2026-09-01 00:00:00',
            ]),
        ];

        $projection = StatusProjector::project($announcements, 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 5, 0.5);

        $byDate = array_column($projection->days, 'level', 'date');

        self::assertSame([
            '2026-08-31' => 'outage',
            '2026-09-01' => 'outage',
            '2026-09-02' => 'operational',
            '2026-09-03' => 'operational',
            '2026-09-04' => 'operational',
        ], $byDate);
    }

    #[Test]
    public function announcement_starting_today_colors_exactly_one_day(): void
    {
        $announcements = [
            $this->announcement([
                'state' => 'active',
                'started_at' => '2026-09-04 09:00:00',
                'ended_at' => null,
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-04 15:00:00'),
            $this->tz(),
            5,
            0.5
        );

        $byDate = array_column($projection->days, 'level', 'date');

        self::assertSame([
            '2026-08-31' => 'operational',
            '2026-09-01' => 'operational',
            '2026-09-02' => 'operational',
            '2026-09-03' => 'operational',
            '2026-09-04' => 'outage',
        ], $byDate);
        self::assertSame('outage', $projection->current);
    }

    #[Test]
    public function future_started_at_colors_nothing(): void
    {
        $announcements = [
            $this->announcement([
                'started_at' => '2026-09-10 00:00:00',
                'ended_at' => '2026-09-11 00:00:00',
            ]),
        ];

        $projection = StatusProjector::project($announcements, 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 5, 0.5);

        foreach ($projection->days as $day) {
            self::assertSame('operational', $day['level']);
        }
        self::assertSame('operational', $projection->current);
        self::assertSame(1.0, $projection->uptime);
    }

    #[Test]
    public function two_overlapping_announcements_on_one_day_resolve_to_the_higher_severity(): void
    {
        $announcements = [
            $this->announcement([
                'severity' => 'partial-outage',
                'started_at' => '2026-09-04 00:00:00',
                'ended_at' => '2026-09-04 06:00:00',
            ]),
            $this->announcement([
                'severity' => 'outage',
                'started_at' => '2026-09-04 03:00:00',
                'ended_at' => '2026-09-04 09:00:00',
            ]),
        ];

        $projection = StatusProjector::project($announcements, 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 1, 0.5);

        self::assertSame('outage', $projection->days[0]['level']);
        self::assertSame('outage', $projection->current);
    }

    #[Test]
    public function reversing_the_order_of_two_overlapping_announcements_still_resolves_to_the_higher_severity(): void
    {
        // Same as above with array order flipped -- pins that resolution is
        // max-over-all, not "last one wins".
        $announcements = [
            $this->announcement([
                'severity' => 'outage',
                'started_at' => '2026-09-04 03:00:00',
                'ended_at' => '2026-09-04 09:00:00',
            ]),
            $this->announcement([
                'severity' => 'partial-outage',
                'started_at' => '2026-09-04 00:00:00',
                'ended_at' => '2026-09-04 06:00:00',
            ]),
        ];

        $projection = StatusProjector::project($announcements, 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 1, 0.5);

        self::assertSame('outage', $projection->days[0]['level']);
    }

    #[Test]
    public function announcement_scoped_to_two_categories_colors_both(): void
    {
        $announcements = [
            $this->announcement([
                'categories' => ['api', 'web'],
                'started_at' => '2026-09-04 00:00:00',
                'ended_at' => '2026-09-04 01:00:00',
            ]),
        ];

        $api = StatusProjector::project($announcements, 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 1, 0.5);
        $web = StatusProjector::project($announcements, 'web', $this->today('2026-09-04 12:00:00'), $this->tz(), 1, 0.5);
        $other = StatusProjector::project($announcements, 'sync', $this->today('2026-09-04 12:00:00'), $this->tz(), 1, 0.5);

        self::assertSame('outage', $api->days[0]['level']);
        self::assertSame('outage', $web->days[0]['level']);
        self::assertSame('operational', $other->days[0]['level']);
    }

    #[Test]
    public function dst_spring_forward_day_is_still_exactly_one_day_and_window_is_still_exactly_window_days(): void
    {
        // America/New_York springs forward on 2026-03-08 (2:00am -> 3:00am).
        $announcements = [
            $this->announcement([
                'started_at' => '2026-03-06 00:00:00',
                'ended_at' => '2026-03-10 00:00:00',
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-03-10 12:00:00', 'America/New_York'),
            $this->tz('America/New_York'),
            5,
            0.5
        );

        $dates = array_column($projection->days, 'date');

        self::assertCount(5, $projection->days);
        self::assertSame(
            ['2026-03-06', '2026-03-07', '2026-03-08', '2026-03-09', '2026-03-10'],
            $dates
        );
        self::assertSame(1, count(array_filter($dates, static fn (string $d): bool => $d === '2026-03-08')));

        foreach ($projection->days as $day) {
            self::assertSame('outage', $day['level']);
        }
    }

    #[Test]
    public function dst_fall_back_day_is_still_exactly_one_day_and_window_is_still_exactly_window_days(): void
    {
        // America/New_York falls back on 2026-11-01 (2:00am -> 1:00am).
        $projection = StatusProjector::project(
            [],
            'api',
            $this->today('2026-11-03 12:00:00', 'America/New_York'),
            $this->tz('America/New_York'),
            5,
            0.5
        );

        $dates = array_column($projection->days, 'date');

        self::assertCount(5, $projection->days);
        self::assertSame(
            ['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03'],
            $dates
        );
    }

    // -- Open-ended interval handling (D9, the "don't skip this" rule) --

    #[Test]
    public function active_announcement_with_null_ended_at_runs_to_now(): void
    {
        $announcements = [
            $this->announcement([
                'state' => 'active',
                'started_at' => '2026-09-02 00:00:00',
                'ended_at' => null,
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-04 12:00:00'),
            $this->tz(),
            5,
            0.5
        );

        $byDate = array_column($projection->days, 'level', 'date');

        self::assertSame([
            '2026-08-31' => 'operational',
            '2026-09-01' => 'operational',
            '2026-09-02' => 'outage',
            '2026-09-03' => 'outage',
            '2026-09-04' => 'outage',
        ], $byDate);
    }

    #[Test]
    public function watching_announcement_with_null_ended_at_runs_to_now(): void
    {
        $announcements = [
            $this->announcement([
                'state' => 'watching',
                'severity' => 'partial-outage',
                'started_at' => '2026-09-03 00:00:00',
                'ended_at' => null,
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-04 12:00:00'),
            $this->tz(),
            5,
            0.5
        );

        $byDate = array_column($projection->days, 'level', 'date');

        self::assertSame([
            '2026-08-31' => 'operational',
            '2026-09-01' => 'operational',
            '2026-09-02' => 'operational',
            '2026-09-03' => 'partial-outage',
            '2026-09-04' => 'partial-outage',
        ], $byDate);
    }

    #[Test]
    public function resolved_announcement_with_null_ended_at_ends_at_its_own_last_modified_time_never_open_ended(): void
    {
        // If this were treated as open-ended, every day through today would
        // be colored -- exactly the bug D9 exists to prevent. Instead it
        // must stop at updated_at's calendar day.
        $announcements = [
            $this->announcement([
                'state' => 'resolved',
                'started_at' => '2026-09-01 00:00:00',
                'ended_at' => null,
                'updated_at' => '2026-09-02 08:00:00',
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-04 12:00:00'),
            $this->tz(),
            5,
            0.5
        );

        $byDate = array_column($projection->days, 'level', 'date');

        self::assertSame([
            '2026-08-31' => 'operational',
            '2026-09-01' => 'outage',
            '2026-09-02' => 'outage',
            '2026-09-03' => 'operational',
            '2026-09-04' => 'operational',
        ], $byDate);
        self::assertSame('operational', $projection->current);
    }

    // -- Uptime formula --

    #[Test]
    public function uptime_formula_at_default_config_with_a_mixed_window(): void
    {
        // windowDays=10, 2 outage days + 2 partial-outage days, weight=0.5.
        // uptime = (10 - 2 - 0.5*2) / 10 = 7/10 = 0.7
        $announcements = [
            $this->announcement([
                'severity' => 'outage',
                'started_at' => '2026-09-01 00:00:00',
                'ended_at' => '2026-09-02 00:00:00',
            ]),
            $this->announcement([
                'severity' => 'partial-outage',
                'started_at' => '2026-09-03 00:00:00',
                'ended_at' => '2026-09-04 00:00:00',
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-10 12:00:00'),
            $this->tz(),
            10,
            0.5
        );

        self::assertEqualsWithDelta(0.7, $projection->uptime, 0.0000001);
    }

    #[Test]
    public function uptime_formula_at_a_non_default_window_days_and_partial_weight(): void
    {
        // windowDays=7, partialWeight=0.25, 1 outage day + 1 partial day.
        // uptime = (7 - 1 - 0.25*1) / 7 = 5.75/7
        $announcements = [
            $this->announcement([
                'severity' => 'outage',
                'started_at' => '2026-09-01 00:00:00',
                'ended_at' => '2026-09-01 12:00:00',
            ]),
            $this->announcement([
                'severity' => 'partial-outage',
                'started_at' => '2026-09-02 00:00:00',
                'ended_at' => '2026-09-02 12:00:00',
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-07 12:00:00'),
            $this->tz(),
            7,
            0.25
        );

        self::assertEqualsWithDelta(5.75 / 7, $projection->uptime, 0.0000001);
    }

    #[Test]
    public function partial_weight_of_zero_excludes_partial_outage_days_from_the_uptime_penalty(): void
    {
        $announcements = [
            $this->announcement([
                'severity' => 'partial-outage',
                'started_at' => '2026-09-04 00:00:00',
                'ended_at' => '2026-09-04 12:00:00',
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-04 12:00:00'),
            $this->tz(),
            1,
            0.0
        );

        self::assertSame(1.0, $projection->uptime);
    }

    #[Test]
    public function partial_weight_of_one_counts_partial_outage_the_same_as_a_full_outage(): void
    {
        $announcements = [
            $this->announcement([
                'severity' => 'partial-outage',
                'started_at' => '2026-09-04 00:00:00',
                'ended_at' => '2026-09-04 12:00:00',
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-04 12:00:00'),
            $this->tz(),
            1,
            1.0
        );

        self::assertSame(0.0, $projection->uptime);
    }

    #[Test]
    public function uptime_is_never_rounded_by_the_projector(): void
    {
        // windowDays=3, 1 outage day, weight=0.5 -> (3-1-0)/3 = 0.6666...
        $announcements = [
            $this->announcement([
                'severity' => 'outage',
                'started_at' => '2026-09-02 00:00:00',
                'ended_at' => '2026-09-02 12:00:00',
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-03 12:00:00'),
            $this->tz(),
            3,
            0.5
        );

        self::assertNotEquals(round($projection->uptime, 2), $projection->uptime);
        self::assertEqualsWithDelta(2 / 3, $projection->uptime, 0.0000001);
    }

    // -- current is derived, not stored (D6) --

    #[Test]
    public function current_is_exactly_todays_entry_level(): void
    {
        $announcements = [
            $this->announcement([
                'severity' => 'partial-outage',
                'started_at' => '2026-09-04 00:00:00',
                'ended_at' => null,
                'state' => 'watching',
            ]),
        ];

        $projection = StatusProjector::project(
            $announcements,
            'api',
            $this->today('2026-09-04 12:00:00'),
            $this->tz(),
            3,
            0.5
        );

        self::assertSame($projection->days[array_key_last($projection->days)]['level'], $projection->current);
        self::assertSame('partial-outage', $projection->current);
    }

    // -- Return shape --

    #[Test]
    public function returns_a_status_projection_value_object(): void
    {
        $projection = StatusProjector::project([], 'api', $this->today(), $this->tz(), 3, 0.5);

        self::assertInstanceOf(StatusProjection::class, $projection);
    }

    #[Test]
    public function each_day_entry_has_exactly_date_and_level_keys(): void
    {
        $projection = StatusProjector::project([], 'api', $this->today(), $this->tz(), 2, 0.5);

        foreach ($projection->days as $day) {
            self::assertSame(['date', 'level'], array_keys($day));
        }
    }

    // -- Performance shape: one pass over the announcement set per category --

    #[Test]
    public function iterates_the_announcement_set_exactly_once_regardless_of_window_size(): void
    {
        $announcements = new CountingAnnouncementIterator([
            $this->announcement([
                'started_at' => '2026-01-01 00:00:00',
                'ended_at' => '2026-01-02 00:00:00',
            ]),
        ]);

        StatusProjector::project($announcements, 'api', $this->today('2026-09-04 12:00:00'), $this->tz(), 365, 0.5);

        self::assertSame(1, $announcements->rewindCount());
    }

    // -- Public windowStart()/activeInterval() (ISSUE-205.4): the rendering
    // layer's "resolved announcements within the window" section must derive
    // its cutoff from the exact same boundary the strip uses, not a second,
    // independently-computed one. --

    #[Test]
    public function window_start_matches_the_left_edge_project_itself_uses(): void
    {
        $today = $this->today('2026-09-04 12:00:00');

        $windowStart = StatusProjector::windowStart($today, $this->tz(), 5);

        $projection = StatusProjector::project([], 'api', $today, $this->tz(), 5, 0.5);

        self::assertSame($windowStart->format('Y-m-d'), $projection->days[0]['date']);
    }

    #[Test]
    public function window_start_is_start_of_day_windowdays_minus_one_before_today(): void
    {
        $windowStart = StatusProjector::windowStart($this->today('2026-09-04 23:59:59'), $this->tz(), 1);

        self::assertSame('2026-09-04', $windowStart->format('Y-m-d'));
        self::assertSame('00:00:00', $windowStart->format('H:i:s'));
    }

    #[Test]
    public function window_start_rejects_windowdays_less_than_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StatusProjector::windowStart($this->today(), $this->tz(), 0);
    }

    #[Test]
    public function window_start_respects_timezone_the_same_way_project_does(): void
    {
        // A UTC instant of 2026-09-05 02:00 is still 2026-09-04 in
        // America/Los_Angeles -- the same DST/timezone case project() itself
        // is pinned against.
        $today = new DateTimeImmutable('2026-09-05 02:00:00', $this->tz());

        $windowStart = StatusProjector::windowStart($today, $this->tz('America/Los_Angeles'), 1);

        self::assertSame('2026-09-04', $windowStart->format('Y-m-d'));
    }

    #[Test]
    public function active_interval_for_active_state_runs_start_to_now(): void
    {
        $now = $this->today('2026-09-04 12:00:00');
        $announcement = $this->announcement(['state' => 'active', 'started_at' => '2026-09-01 00:00:00', 'ended_at' => null]);

        [$start, $end] = StatusProjector::activeInterval($announcement, $now, $this->tz());

        self::assertSame('2026-09-01 00:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame($now->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function active_interval_for_resolved_state_ends_at_ended_at_when_present(): void
    {
        $now = $this->today('2026-09-04 12:00:00');
        $announcement = $this->announcement([
            'state' => 'resolved',
            'started_at' => '2026-09-01 00:00:00',
            'ended_at' => '2026-09-02 00:00:00',
        ]);

        [, $end] = StatusProjector::activeInterval($announcement, $now, $this->tz());

        self::assertSame('2026-09-02 00:00:00', $end->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function active_interval_for_resolved_state_with_null_ended_at_ends_at_updated_at_never_now(): void
    {
        $now = $this->today('2026-09-04 12:00:00');
        $announcement = $this->announcement([
            'state' => 'resolved',
            'started_at' => '2026-09-01 00:00:00',
            'ended_at' => null,
            'updated_at' => '2026-09-01 06:00:00',
        ]);

        [, $end] = StatusProjector::activeInterval($announcement, $now, $this->tz());

        self::assertSame('2026-09-01 06:00:00', $end->format('Y-m-d H:i:s'));
        self::assertNotSame($now->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
    }
}

/**
 * Test double that counts how many times it has been iterated
 * (rewind() is called once per `foreach`). Used to pin the "one pass over
 * the announcement set per category -- not a nested loop over each day
 * re-scanning announcements" performance AC deterministically, without a
 * flaky timing-based test.
 */
final class CountingAnnouncementIterator implements Iterator
{
    private int $rewindCount = 0;
    private int $position = 0;

    /** @param array<int, array<string, mixed>> $items */
    public function __construct(private readonly array $items)
    {
    }

    public function rewindCount(): int
    {
        return $this->rewindCount;
    }

    public function rewind(): void
    {
        $this->rewindCount++;
        $this->position = 0;
    }

    public function valid(): bool
    {
        return isset($this->items[$this->position]);
    }

    public function current(): mixed
    {
        return $this->items[$this->position];
    }

    public function key(): mixed
    {
        return $this->position;
    }

    public function next(): void
    {
        $this->position++;
    }
}
