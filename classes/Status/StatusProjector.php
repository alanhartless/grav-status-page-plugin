<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pure, framework-free projection of a category's current status, its
 * day-by-day history strip, and its uptime percentage, derived entirely from
 * a flat list of announcement arrays. No stored daily record, no stored
 * current-status field, no manual override: every value here is
 * recomputed from announcements on every call.
 *
 * Input announcements are plain arrays shaped like:
 *   state:       'active'|'watching'|'resolved'
 *   severity:    'none'|'partial-outage'|'outage'
 *   categories:  list<string>
 *   started_at:  string (parseable by DateTimeImmutable in $timezone) | int (unix timestamp)
 *   ended_at:    string|int|null
 *   updated_at:  string|int -- the announcement's own last-modified moment.
 *                Only consulted when state is 'resolved' and ended_at is
 *                null (see activeInterval()); irrelevant otherwise.
 *
 * `FlexAnnouncementAdapter` is the only piece of this plugin that converts
 * real Flex objects into this shape -- this class never touches Flex, the
 * Grav container, or the filesystem.
 */
final class StatusProjector
{
    private const LEVEL_OPERATIONAL = 'operational';
    private const LEVEL_WATCHING = 'watching';
    private const LEVEL_PARTIAL_OUTAGE = 'partial-outage';
    private const LEVEL_OUTAGE = 'outage';

    private const RANK_OPERATIONAL = 0;
    private const RANK_PARTIAL_OUTAGE = 1;
    private const RANK_OUTAGE = 2;

    /** Severity string -> rank. Only the two severities that feed FR-4 are
     *  present here; 'none' (and anything unrecognized) is looked up as
     *  missing and skipped -- see project(). */
    private const SEVERITY_RANK = [
        self::LEVEL_PARTIAL_OUTAGE => self::RANK_PARTIAL_OUTAGE,
        self::LEVEL_OUTAGE => self::RANK_OUTAGE,
    ];

    private const LEVEL_BY_RANK = [
        self::RANK_OPERATIONAL => self::LEVEL_OPERATIONAL,
        self::RANK_PARTIAL_OUTAGE => self::LEVEL_PARTIAL_OUTAGE,
        self::RANK_OUTAGE => self::LEVEL_OUTAGE,
    ];

    /**
     * The category's *live* status, right now -- deliberately separate from
     * `project()`'s `StatusProjection::$current`, which is today's cell in
     * the day-by-day history strip. Those are different questions: a day
     * that had an outage should permanently show as having had one in the
     * strip, even after the outage is resolved later the same day, but the
     * banner and a category's status badge should revert to `operational`
     * the moment the last affecting announcement is actually resolved --
     * confirmed as a real bug otherwise (resolving an outage announcement
     * left the banner and category badge stuck on `outage` until midnight,
     * because they were reusing today's -- correctly still colored --
     * history-strip cell).
     *
     * No date arithmetic is needed here, unlike `project()`: `resolved` by
     * definition means "not ongoing," so only `active`/`watching`
     * announcements can ever contribute, full stop -- an interval-overlap
     * check against `now` would just be a more roundabout way of asking the
     * same question `project()` already answers for the strip.
     *
     * `watching` is a fourth, state-driven level distinct from severity: a
     * `watching` announcement's own `severity` never contributes to the
     * severity-based rank at all -- only `active` announcements do. A
     * single still-`active` announcement for the category always decides
     * the level (its worst severity among just the `active` ones, ignoring
     * any co-occurring `watching` announcement's severity entirely, however
     * severe). Only once there is no `active` announcement left, but at
     * least one `watching` one with real severity, does the category fall
     * back to the `watching` level -- ranked between `operational` and
     * `partial-outage`, since "we're monitoring something" is less alarming
     * than a confirmed ongoing issue but still worth flagging over plain
     * `operational`.
     *
     * Confirmed live: two announcements for the same category, one `active`
     * with `partial-outage` severity and one `watching` with `outage`
     * severity, must show `partial-outage` (the active one's own severity)
     * -- not `outage`, and not `watching` either, since something IS
     * confirmed still active.
     *
     * @param iterable<array<string, mixed>> $announcements Plain announcement
     *   arrays, in any order.
     * @param string $categoryKey Only announcements listing this category
     *   (in their `categories` array) count.
     * @return 'operational'|'watching'|'partial-outage'|'outage'
     */
    public static function liveStatus(iterable $announcements, string $categoryKey): string
    {
        $activeRank = self::RANK_OPERATIONAL;
        $hasActive = false;
        $hasWatchingWithSeverity = false;

        foreach ($announcements as $announcement) {
            $state = (string) ($announcement['state'] ?? '');
            if ($state !== 'active' && $state !== 'watching') {
                continue;
            }

            $categories = $announcement['categories'] ?? [];
            if (!is_array($categories) || !in_array($categoryKey, $categories, true)) {
                continue;
            }

            $severity = (string) ($announcement['severity'] ?? 'none');
            $severityRank = self::SEVERITY_RANK[$severity] ?? null;

            if ($state === 'active') {
                $hasActive = true;
                if ($severityRank !== null && $severityRank > $activeRank) {
                    $activeRank = $severityRank;
                }
            } elseif ($severityRank !== null) {
                $hasWatchingWithSeverity = true;
            }
        }

        if ($hasActive && $activeRank !== self::RANK_OPERATIONAL) {
            return self::LEVEL_BY_RANK[$activeRank];
        }

        return $hasWatchingWithSeverity ? self::LEVEL_WATCHING : self::LEVEL_OPERATIONAL;
    }

    /**
     * @param iterable<array<string, mixed>> $announcements Plain announcement
     *   arrays, in any order -- iterated exactly once (AC: one pass over the
     *   announcement set per category, never a nested per-day re-scan).
     * @param string $categoryKey Only announcements listing this category
     *   (in their `categories` array) can color a day.
     * @param DateTimeImmutable $today The "now" moment. Re-interpreted in
     *   $timezone both to decide which calendar day is "today" (the window's
     *   right edge) and as "now" for an open-ended active/watching interval.
     * @param DateTimeZone $timezone The single source of "which day is it"
     *   for this call -- callers resolve plugin config / Grav's
     *   system.timezone / UTC fallback before calling in (that resolution is
     *   the adapter's job, not this class's).
     * @param int $windowDays Window length in days, config-driven (default
     *   90 at the plugin level) -- never hardcoded here.
     * @param float $partialWeight 0..1, config-driven (default 0.5 at the
     *   plugin level) -- never hardcoded here.
     */
    public static function project(
        iterable $announcements,
        string $categoryKey,
        DateTimeImmutable $today,
        DateTimeZone $timezone,
        int $windowDays,
        float $partialWeight
    ): StatusProjection {
        if ($windowDays < 1) {
            throw new InvalidArgumentException('windowDays must be at least 1.');
        }

        $now = $today->setTimezone($timezone);
        $windowStart = self::windowStart($today, $timezone, $windowDays);

        /** @var list<int> $ranks Index 0 = windowStart's day, last index = today. */
        $ranks = array_fill(0, $windowDays, self::RANK_OPERATIONAL);

        // Single pass over the announcement set -- each announcement updates
        // only the (typically few) day slots its own interval clamps to,
        // never a day-major loop that re-scans the whole announcement list.
        foreach ($announcements as $announcement) {
            $severity = (string) ($announcement['severity'] ?? 'none');
            $rank = self::SEVERITY_RANK[$severity] ?? null;
            if ($rank === null) {
                continue;
            }

            $categories = $announcement['categories'] ?? [];
            if (!is_array($categories) || !in_array($categoryKey, $categories, true)) {
                continue;
            }

            // status-announcements is hand-editable YAML on the persistent
            // volume (`user-data://flex-objects/status-announcements`) --
            // Admin2/the API already reject an unparseable started_at/
            // ended_at (StatusAnnouncementValidator), but a directly-edited
            // file can still contain one. One malformed record must not take
            // the whole public page down for every category; it is skipped
            // instead, contributing nothing, same as if it did not exist.
            try {
                [$start, $end] = self::activeInterval($announcement, $now, $timezone);
            } catch (\Throwable $exception) {
                continue;
            }

            $firstDayIndex = self::calendarDayIndex($windowStart, $start);
            $lastDayIndex = self::calendarDayIndex($windowStart, $end);

            if ($lastDayIndex < 0 || $firstDayIndex > $windowDays - 1) {
                continue; // No overlap with the window at all.
            }

            $from = max(0, $firstDayIndex);
            $to = min($windowDays - 1, $lastDayIndex);

            for ($i = $from; $i <= $to; $i++) {
                if ($rank > $ranks[$i]) {
                    $ranks[$i] = $rank;
                }
            }
        }

        $days = [];
        $outageDays = 0;
        $partialDays = 0;
        $date = $windowStart;

        foreach ($ranks as $rank) {
            $days[] = [
                'date' => $date->format('Y-m-d'),
                'level' => self::LEVEL_BY_RANK[$rank],
            ];

            if ($rank === self::RANK_OUTAGE) {
                $outageDays++;
            } elseif ($rank === self::RANK_PARTIAL_OUTAGE) {
                $partialDays++;
            }

            $date = $date->modify('+1 day');
        }

        $uptime = ($windowDays - $outageDays - $partialWeight * $partialDays) / $windowDays;
        $current = $days[array_key_last($days)]['level'];

        return new StatusProjection($current, $days, $uptime);
    }

    /**
     * The window's start moment (start-of-day, `$windowDays` before `$today`
     * inclusive), computed in `$timezone`. Public so the rendering layer can
     * derive the "resolved announcements within the last `windowDays` days"
     * section from the **exact same** window boundary the strip itself
     * uses, rather than recomputing it independently -- that's exactly why
     * this was extracted from project() rather than duplicated at the call
     * site.
     */
    public static function windowStart(DateTimeImmutable $today, DateTimeZone $timezone, int $windowDays): DateTimeImmutable
    {
        if ($windowDays < 1) {
            throw new InvalidArgumentException('windowDays must be at least 1.');
        }

        $todayStart = self::startOfDay($today->setTimezone($timezone));

        return $todayStart->modify('-' . ($windowDays - 1) . ' days');
    }

    /**
     * An announcement's active interval is [started_at, ended_at] (closed
     * both ends). Null ended_at means different things depending on state:
     *
     *  - active/watching: still ongoing -- the interval runs to "now".
     *  - resolved: ends at the announcement's own last-modified moment
     *    (updated_at), NEVER open-ended. Without this rule one
     *    mis-authored record with no ended_at would paint every day since
     *    red, forever.
     *
     * Public so the "resolved announcements from the last windowDays days"
     * section applies the identical open-ended rule used by the strip,
     * instead of a second, potentially-diverging copy.
     *
     * @param array<string, mixed> $announcement
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} [start, end]
     */
    public static function activeInterval(
        array $announcement,
        DateTimeImmutable $now,
        DateTimeZone $timezone
    ): array {
        $start = self::parseMoment($announcement['started_at'], $timezone);

        $endedAt = $announcement['ended_at'] ?? null;
        if ($endedAt !== null && $endedAt !== '') {
            $end = self::parseMoment($endedAt, $timezone);
        } elseif (($announcement['state'] ?? null) === 'resolved') {
            $end = self::parseMoment($announcement['updated_at'], $timezone);
        } else {
            $end = $now;
        }

        return [$start, $end];
    }

    private static function parseMoment(int|string $value, DateTimeZone $timezone): DateTimeImmutable
    {
        if (is_int($value)) {
            $moment = new DateTimeImmutable('@' . $value);
        } else {
            $moment = new DateTimeImmutable($value, $timezone);
        }

        return $moment->setTimezone($timezone);
    }

    private static function startOfDay(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTime(0, 0, 0, 0);
    }

    /**
     * Signed number of calendar days between $windowStart (already a
     * start-of-day moment) and $moment's own calendar day, in $windowStart's
     * timezone. Computed via DateTimeImmutable::diff() rather than a fixed
     * 86400-second division, so a DST transition inside the range never
     * shifts the result by an hour's worth of a day.
     */
    private static function calendarDayIndex(DateTimeImmutable $windowStart, DateTimeImmutable $moment): int
    {
        $momentDay = self::startOfDay($moment->setTimezone($windowStart->getTimezone()));
        $diff = $windowStart->diff($momentDay);
        $days = (int) $diff->format('%a');

        return $diff->invert ? -$days : $days;
    }
}
