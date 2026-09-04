<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

/**
 * The public page's overall banner level: the worst of every category's own
 * `current` level -- stating plainly whether every category is currently
 * operational. Mirrors `StatusProjector`'s own severity order (`outage` >
 * `partial-outage` > `operational`) rather than a second, potentially-
 * diverging rank table.
 */
final class OverallStatus
{
    private const RANK = [
        'operational' => 0,
        'partial-outage' => 1,
        'outage' => 2,
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
