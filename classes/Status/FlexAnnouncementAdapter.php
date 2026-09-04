<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

/**
 * Thin adapter converting `status-announcements` Flex objects into the
 * plain array shape `StatusProjector::project()` consumes. This is the only
 * piece of the status-projection logic that touches Flex objects at all --
 * kept deliberately thin so the actual interval-overlap/day-boundary logic
 * in StatusProjector stays completely framework-free and gets the
 * exhaustive test coverage instead.
 *
 * Duck-typed rather than type-hinted against a concrete Flex class: any
 * object exposing `state`/`severity`/`categories`/`started_at`/`ended_at`
 * properties (real or magic) plus a `getTimestamp(): int` method works here
 * -- in production that is `StatusAnnouncementObject`
 * (`Grav\Framework\Flex\FlexObject::getTimestamp()`, the storage file's
 * last-modified time), in this class's own unit test it is a plain stub
 * with no Grav dependency. Duck typing (rather than importing the real Flex
 * interface) also keeps this file loadable and testable without Grav or
 * `flex-objects` present on the autoloader at all.
 */
final class FlexAnnouncementAdapter
{
    /**
     * @param iterable<object> $announcements
     * @return list<array{
     *     state: string,
     *     severity: string,
     *     categories: list<string>,
     *     started_at: string,
     *     ended_at: string|null,
     *     updated_at: int,
     * }>
     */
    public static function toArrays(iterable $announcements): array
    {
        $result = [];

        foreach ($announcements as $announcement) {
            $result[] = self::toArray($announcement);
        }

        return $result;
    }

    /**
     * @return array{
     *     state: string,
     *     severity: string,
     *     categories: list<string>,
     *     started_at: string,
     *     ended_at: string|null,
     *     updated_at: int,
     * }
     */
    public static function toArray(object $announcement): array
    {
        $endedAt = $announcement->ended_at ?? null;

        return [
            'state' => (string) ($announcement->state ?? 'active'),
            'severity' => (string) ($announcement->severity ?? 'none'),
            'categories' => array_values((array) ($announcement->categories ?? [])),
            'started_at' => (string) ($announcement->started_at ?? ''),
            'ended_at' => ($endedAt === null || $endedAt === '') ? null : (string) $endedAt,
            'updated_at' => $announcement->getTimestamp(),
        ];
    }
}
