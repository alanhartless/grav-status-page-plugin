<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The public page's two announcement sections: `active`/`watching`
 * announcements up top, and `resolved` announcements from the last
 * `windowDays` days at the bottom, newest first.
 *
 * Pure over the plain array shape `FlexAnnouncementAdapter::toArray()`
 * produces, keyed by an opaque string identity (in production, the Flex
 * object's own storage key) rather than operating on Flex objects directly
 * -- the same "framework-free logic, thin adapter at the edge" split
 * `StatusProjector` already uses. The rendering glue maps a returned key
 * back to its original Flex object for template display.
 *
 * The resolved section derives its window boundary from
 * `StatusProjector::windowStart()` and its open-ended-interval rule from
 * `StatusProjector::activeInterval()` -- the exact same values the daily
 * strip uses, so the two can never drift apart by being computed
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

            // Same hand-editable-YAML defense as StatusProjector::project():
            // an unparseable started_at/ended_at must not crash this section
            // for every other resolved announcement. The record's placement
            // in the window can't be determined, so it is excluded rather
            // than guessed at.
            try {
                [, $end] = StatusProjector::activeInterval($announcement, $now, $timezone);
            } catch (\Throwable $exception) {
                continue;
            }

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
            if ($moment instanceof DateTimeImmutable) {
                $normalized[$key] = $moment;
                continue;
            }

            // An active/watching announcement is currently impacting users --
            // it must still be shown even if its started_at is unparseable
            // hand-edited YAML. It can't be placed correctly in the
            // newest-first order, so it sorts as the oldest entry (the
            // epoch) rather than crashing the whole section.
            try {
                $normalized[$key] = self::parse($moment);
            } catch (\Throwable $exception) {
                $normalized[$key] = new DateTimeImmutable('@0');
            }
        }

        uasort($normalized, static fn(DateTimeImmutable $a, DateTimeImmutable $b): int => $b <=> $a);

        return array_keys($normalized);
    }

    private static function parse(int|string $value): DateTimeImmutable
    {
        return is_int($value) ? new DateTimeImmutable('@' . $value) : new DateTimeImmutable($value);
    }
}
