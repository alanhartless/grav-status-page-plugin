<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

use DateTimeImmutable;
use Grav\Common\Config\Config;
use Grav\Framework\Flex\Flex;

/**
 * Assembles the public status page's Twig data from live Flex objects and
 * plugin config. This is the plugin's one Grav-coupled call site (matching
 * `StatusProjector`'s and `TimezoneResolver`'s own "resolution happens at
 * the call site, not in framework-free classes" split) -- it reads
 * `plugins.status-page.*` and `system.timezone`, fetches the two Flex
 * directories, and hands plain arrays to the pure classes under this
 * namespace. Not unit-tested directly (it needs a live `$grav['flex']`);
 * exercised by a Grav-CLI verification pass instead, the same substitution
 * used elsewhere in this plugin for Flex-container-dependent code.
 */
final class StatusPagePresenter
{
    /**
     * @return array{
     *     page_title: string,
     *     window_days: int,
     *     banner: string,
     *     banner_message_watching: string,
     *     banner_message_outage: string,
     *     active_watching: list<object>,
     *     categories: list<array{key: string, title: string, description: ?string, current: string, days: list<array{date: string, level: string}>, uptime: float}>,
     *     resolved: list<object>,
     * } `banner` and each category's `current` are live status
     *   (`StatusProjector::liveStatus()`) -- whatever's ongoing right now,
     *   not today's history-strip cell. `days` (unaffected) is the
     *   permanent per-day historical record.
     */
    public static function build(Flex $flex, Config $config, DateTimeImmutable $now): array
    {
        $windowDays = (int) $config->get('plugins.status-page.window_days', 90);
        $partialWeight = (float) $config->get('plugins.status-page.uptime_partial_weight', 0.5);
        $timezone = TimezoneResolver::resolve(
            (string) $config->get('plugins.status-page.timezone', ''),
            (string) $config->get('system.timezone', '')
        );

        $categoryObjects = [];
        $categoriesDirectory = $flex->getDirectory('status-categories');
        if ($categoriesDirectory) {
            foreach ($categoriesDirectory->getCollection() as $category) {
                $categoryObjects[$category->getKey()] = $category;
            }
        }

        $announcementObjects = [];
        $announcementArrays = [];
        $announcementsDirectory = $flex->getDirectory('status-announcements');
        if ($announcementsDirectory) {
            foreach ($announcementsDirectory->getCollection() as $announcement) {
                $key = $announcement->getKey();
                $announcementObjects[$key] = $announcement;
                $announcementArrays[$key] = FlexAnnouncementAdapter::toArray($announcement);
            }
        }

        $categoryRows = [];
        foreach ($categoryObjects as $key => $category) {
            $categoryRows[] = [
                'key' => (string) $key,
                'title' => (string) ($category->title ?? $key),
                'order' => (int) ($category->order ?? 0),
            ];
        }
        $orderedKeys = CategoryOrdering::orderedKeys($categoryRows);

        $categories = [];
        $currents = [];
        foreach ($orderedKeys as $key) {
            $category = $categoryObjects[$key];

            $projection = StatusProjector::project(
                $announcementArrays,
                $key,
                $now,
                $timezone,
                $windowDays,
                $partialWeight
            );

            // Deliberately not $projection->current: that's today's
            // permanent history-strip cell, not "is anything ongoing right
            // now" -- see StatusProjector::liveStatus() for why the two
            // differ once something is resolved same-day.
            $liveStatus = StatusProjector::liveStatus($announcementArrays, $key);

            $currents[] = $liveStatus;
            $categories[] = [
                'key' => $key,
                'title' => (string) ($category->title ?? $key),
                'description' => $category->description ?? null,
                'current' => $liveStatus,
                'days' => $projection->days,
                'uptime' => $projection->uptime,
            ];
        }

        $activeWatchingKeys = AnnouncementSections::activeAndWatchingKeys($announcementArrays);
        $resolvedKeys = AnnouncementSections::resolvedWithinWindowKeys($announcementArrays, $now, $timezone, $windowDays);

        return [
            'page_title' => (string) $config->get('plugins.status-page.page_title', 'Status'),
            'window_days' => $windowDays,
            'banner' => OverallStatus::fromCurrents($currents),
            'banner_message_watching' => (string) $config->get(
                'plugins.status-page.banner_message_watching',
                "All systems have recovered and we're actively monitoring the situation."
            ),
            'banner_message_outage' => (string) $config->get(
                'plugins.status-page.banner_message_outage',
                'Some systems are experiencing an outage.'
            ),
            'active_watching' => array_map(static fn($key) => $announcementObjects[$key], $activeWatchingKeys),
            'categories' => $categories,
            'resolved' => array_map(static fn($key) => $announcementObjects[$key], $resolvedKeys),
        ];
    }
}
