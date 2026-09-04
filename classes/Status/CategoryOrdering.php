<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

/**
 * Category display order for the public page: `order` ascending, then
 * `title` for stable ties. Same rule `CategoryOptions::formatOptions()`
 * applies to the admin form's dynamic option list, factored out here so the
 * two call sites share one tested implementation instead of two copies
 * that can drift apart.
 */
final class CategoryOrdering
{
    /**
     * @param list<array{key: string, title: string, order?: int}> $rows
     * @return list<string> Category keys in display order.
     */
    public static function orderedKeys(array $rows): array
    {
        usort($rows, static function (array $a, array $b): int {
            $orderCompare = ($a['order'] ?? 0) <=> ($b['order'] ?? 0);

            return $orderCompare !== 0 ? $orderCompare : $a['title'] <=> $b['title'];
        });

        return array_map(static fn(array $row): string => $row['key'], $rows);
    }
}
