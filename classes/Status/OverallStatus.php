<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

/**
 * The public page's overall banner level: the worst of every category's own
 * `current` level -- stating plainly whether every category is currently
 * operational. Mirrors `StatusProjector::liveStatus()`'s own level order
 * (`outage` > `partial-outage` > `watching` > `operational`) rather than a
 * second, potentially-diverging rank table. `watching` ranks just above
 * `operational`: a category that's only being monitored, with nothing
 * currently confirmed active anywhere, must never outrank one with a real
 * ongoing outage or partial outage elsewhere.
 */
final class OverallStatus
{
    private const RANK = [
        'operational' => 0,
        'watching' => 1,
        'partial-outage' => 2,
        'outage' => 3,
    ];

    /**
     * @param list<string> $currents Each category's `StatusProjection::$current`.
     */
    public static function fromCurrents(array $currents): string
    {
        $best = 'operational';
        $bestRank = self::RANK[$best];

        foreach ($currents as $current) {
            $rank = self::RANK[$current] ?? null;
            if ($rank !== null && $rank > $bestRank) {
                $best = $current;
                $bestRank = $rank;
            }
        }

        return $best;
    }
}
