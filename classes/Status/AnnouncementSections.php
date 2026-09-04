<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The public page's two announcement sections (ISSUE-205.4 AC): `active`/
 * `watching` announcements up top, and `resolved` announcements from the
 * last `windowDays` days at the bottom, newest first.
 *
 * Pure over the plain array shape `FlexAnnouncementAdapter::toArray()`
 * produces, keyed by an opaque string identity (in production, the Flex
 * object's own storage key) rather than operating on Flex objects directly
 * -- same "framework-free logic, thin adapter at the edge" split
 * `StatusProjector` (ISSUE-205.3) already established. The rendering glue
 * maps a returned key back to its original Flex object for template display.
 *
 * The resolved section derives its window boundary from
 * `StatusProjector::windowStart()` and its open-ended-interval rule from
 * `StatusProjector::activeInterval()` -- the exact same values the daily
 * strip uses, per this issue's own AC that the two must not be computed
 * independently.
 */
final class AnnouncementSections
{
    /**
     * @param array<string, array<string, mixed>> $keyedAnnouncements
     * @return list<string> Keys of active/watching announcements, newest
     *   `started_at` first.
     */
    public static function activeAndWatchingKeys(array $keyedAnnouncements): array
    {
        $matches = [];

        foreach ($keyedAnnouncements as $key => $announcement) {
            $state = (string) ($announcement['state'] ?? '');
            if ($state === 'active' || $state === 'watching') {
                $matches[$key] = $announcement['started_at'];
            }
        }

        return self::sortKeysByMomentDesc($matches);
    }

    /**
     * @param array<string, array<string, mixed>> $keyedAnnouncements
     * @return list<string> Keys of `resolved` announcements whose active
     *   interval ends on or after the window's start, newest end first.
     */
    public static function resolvedWithinWindowKeys(
        array $keyedAnnouncements,
        DateTimeImmutable $today,
        DateTimeZone $timezone,
        int $windowDays
    ): array {
        $windowStart = StatusProjector::windowStart($today, $timezone, $windowDays);
        $now = $today->setTimezone($timezone);

        $matches = [];

        foreach ($keyedAnnouncements as $key => $announcement) {
            if (($announcement['state'] ?? null) !== 'resolved') {
                continue;
            }

            [, $end] = StatusProjector::activeInterval($announcement, $now, $timezone);

            if ($end >= $windowStart) {
                $matches[$key] = $end;
            }
        }

        return self::sortKeysByMomentDesc($matches);
    }

    /**
     * @param array<string, string|int|DateTimeImmutable> $keyedMoments
     * @return list<string>
     */
    private static function sortKeysByMomentDesc(array $keyedMoments): array
    {
        $normalized = [];
        foreach ($keyedMoments as $key => $moment) {
            $normalized[$key] = $moment instanceof DateTimeImmutable
                ? $moment
                : self::parse($moment);
        }

        uasort($normalized, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $b <=> $a);

        return array_keys($normalized);
    }

    private static function parse(int|string $value): DateTimeImmutable
    {
        return is_int($value) ? new DateTimeImmutable('@' . $value) : new DateTimeImmutable($value);
    }
}
