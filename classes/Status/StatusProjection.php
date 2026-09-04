<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

/**
 * Immutable result of StatusProjector::project() for one category: its
 * current status, its day-by-day history strip, and its uptime percentage.
 */
final class StatusProjection
{
    /**
     * @param 'operational'|'partial-outage'|'outage' $current Today's cell
     *   in the day-by-day history strip -- a permanent historical record of
     *   the worst thing that happened that calendar day, which does NOT
     *   revert to `operational` just because it was resolved later the same
     *   day. NOT the same thing as live/right-now status -- see
     *   `StatusProjector::liveStatus()` for that, used by the banner and a
     *   category's status badge instead.
     * @param list<array{date: string, level: 'operational'|'partial-outage'|'outage'}> $days
     *   Exactly `windowDays` entries, oldest first, ending today.
     * @param float $uptime 0..1, unrounded.
     */
    public function __construct(
        public readonly string $current,
        public readonly array $days,
        public readonly float $uptime,
    ) {
    }
}
