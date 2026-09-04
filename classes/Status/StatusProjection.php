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
     * @param 'operational'|'partial-outage'|'outage' $current
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
