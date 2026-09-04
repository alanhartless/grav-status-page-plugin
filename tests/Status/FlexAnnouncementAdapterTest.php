<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests\Status;

use Grav\Plugin\StatusPage\Status\FlexAnnouncementAdapter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FlexAnnouncementAdapter is a thin, duck-typed mapping from a Flex
 * announcement object to the plain array StatusProjector::project()
 * consumes. It carries no interval/severity logic of its own -- that's all
 * StatusProjector's job and is exhaustively covered in
 * StatusProjectorTest -- so this suite only pins the mapping itself.
 */
final class FlexAnnouncementAdapterTest extends TestCase
{
    #[Test]
    public function maps_a_full_announcement_object_to_the_expected_array_shape(): void
    {
        $announcement = new StubFlexAnnouncement(
            state: 'resolved',
            severity: 'outage',
            categories: ['api', 'web'],
            startedAt: '2026-09-01 10:00',
            endedAt: '2026-09-01 12:00',
            timestamp: 1_756_724_400
        );

        $array = FlexAnnouncementAdapter::toArray($announcement);

        self::assertSame([
            'state' => 'resolved',
            'severity' => 'outage',
            'categories' => ['api', 'web'],
            'started_at' => '2026-09-01 10:00',
            'ended_at' => '2026-09-01 12:00',
            'updated_at' => 1_756_724_400,
        ], $array);
    }

    #[Test]
    public function a_null_ended_at_maps_to_null_not_an_empty_string(): void
    {
        $announcement = new StubFlexAnnouncement(
            state: 'active',
            severity: 'outage',
            categories: ['api'],
            startedAt: '2026-09-01 10:00',
            endedAt: null,
            timestamp: 1_756_724_400
        );

        $array = FlexAnnouncementAdapter::toArray($announcement);

        self::assertNull($array['ended_at']);
    }

    #[Test]
    public function an_empty_string_ended_at_maps_to_null(): void
    {
        // Grav's blueprint layer can round-trip an unset datetime field as
        // '' rather than a true null -- normalize both to null so
        // StatusProjector only ever has to handle one "unset" representation.
        $announcement = new StubFlexAnnouncement(
            state: 'active',
            severity: 'outage',
            categories: ['api'],
            startedAt: '2026-09-01 10:00',
            endedAt: '',
            timestamp: 1_756_724_400
        );

        $array = FlexAnnouncementAdapter::toArray($announcement);

        self::assertNull($array['ended_at']);
    }

    #[Test]
    public function missing_state_and_severity_default_to_active_and_none(): void
    {
        $announcement = new StubFlexAnnouncement(
            state: null,
            severity: null,
            categories: ['api'],
            startedAt: '2026-09-01 10:00',
            endedAt: null,
            timestamp: 1_756_724_400
        );

        $array = FlexAnnouncementAdapter::toArray($announcement);

        self::assertSame('active', $array['state']);
        self::assertSame('none', $array['severity']);
    }

    #[Test]
    public function no_categories_maps_to_an_empty_list(): void
    {
        $announcement = new StubFlexAnnouncement(
            state: 'active',
            severity: 'outage',
            categories: null,
            startedAt: '2026-09-01 10:00',
            endedAt: null,
            timestamp: 1_756_724_400
        );

        $array = FlexAnnouncementAdapter::toArray($announcement);

        self::assertSame([], $array['categories']);
    }

    #[Test]
    public function to_arrays_maps_an_iterable_of_announcements_in_order(): void
    {
        $announcements = [
            new StubFlexAnnouncement('active', 'outage', ['api'], '2026-09-01 10:00', null, 1),
            new StubFlexAnnouncement('resolved', 'partial-outage', ['web'], '2026-09-02 10:00', '2026-09-02 12:00', 2),
        ];

        $arrays = FlexAnnouncementAdapter::toArrays($announcements);

        self::assertCount(2, $arrays);
        self::assertSame('active', $arrays[0]['state']);
        self::assertSame('resolved', $arrays[1]['state']);
    }
}

/**
 * Minimal duck-typed stand-in for `StatusAnnouncementObject` -- exposes the
 * same properties plus `getTimestamp()`, with no Grav/Flex dependency at
 * all, matching FlexAnnouncementAdapter's actual (duck-typed) contract.
 */
final class StubFlexAnnouncement
{
    /**
     * @param list<string>|null $categories
     */
    public function __construct(
        private readonly ?string $state,
        private readonly ?string $severity,
        private readonly ?array $categories,
        private readonly ?string $startedAt,
        private readonly ?string $endedAt,
        private readonly int $timestamp,
    ) {
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'state' => $this->state,
            'severity' => $this->severity,
            'categories' => $this->categories,
            'started_at' => $this->startedAt,
            'ended_at' => $this->endedAt,
            default => null,
        };
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }
}
