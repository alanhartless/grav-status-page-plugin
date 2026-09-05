<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests\Flex\Types\StatusAnnouncement;

use Grav\Plugin\StatusPage\Flex\Types\StatusAnnouncement\StatusAnnouncementValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cross-field validation rules for status-announcements. These rules must
 * hold regardless of what the Admin2 form itself enforces,
 * so they live in a plain-PHP class with no Grav dependency -- testable
 * without a Grav bootstrap -- and are called from StatusAnnouncementObject.
 */
final class StatusAnnouncementValidatorTest extends TestCase
{
    #[Test]
    public function valid_active_announcement_with_no_ended_at_passes(): void
    {
        $errors = StatusAnnouncementValidator::validate([
            'state' => 'active',
            'started_at' => '2026-09-01 10:00',
            'ended_at' => null,
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function resolved_without_ended_at_passes(): void
    {
        // No longer rejected here -- StatusAnnouncementObject auto-fills
        // ended_at before this validator ever sees a resolved announcement
        // missing one. This validator only checks ordering.
        $errors = StatusAnnouncementValidator::validate([
            'state' => 'resolved',
            'started_at' => '2026-09-01 10:00',
            'ended_at' => null,
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function resolved_with_ended_at_passes(): void
    {
        $errors = StatusAnnouncementValidator::validate([
            'state' => 'resolved',
            'started_at' => '2026-09-01 10:00',
            'ended_at' => '2026-09-01 12:00',
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function ended_at_before_started_at_is_rejected(): void
    {
        $errors = StatusAnnouncementValidator::validate([
            'state' => 'watching',
            'started_at' => '2026-09-01 12:00',
            'ended_at' => '2026-09-01 10:00',
        ]);

        self::assertSame(
            [StatusAnnouncementValidator::ERROR_ENDED_AT_BEFORE_STARTED_AT],
            $errors
        );
    }

    #[Test]
    public function ended_at_equal_to_started_at_passes(): void
    {
        $errors = StatusAnnouncementValidator::validate([
            'state' => 'resolved',
            'started_at' => '2026-09-01 12:00',
            'ended_at' => '2026-09-01 12:00',
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function resolved_and_ended_before_started_reports_both_errors(): void
    {
        $errors = StatusAnnouncementValidator::validate([
            'state' => 'resolved',
            'started_at' => '2026-09-01 12:00',
            'ended_at' => '2026-09-01 10:00',
        ]);

        self::assertSame(
            [StatusAnnouncementValidator::ERROR_ENDED_AT_BEFORE_STARTED_AT],
            $errors
        );
    }

    #[Test]
    public function missing_started_at_does_not_trigger_the_ordering_rule(): void
    {
        // started_at is separately required=true at the blueprint level; this
        // validator only checks ordering when both timestamps are present.
        $errors = StatusAnnouncementValidator::validate([
            'state' => 'watching',
            'started_at' => null,
            'ended_at' => '2026-09-01 10:00',
        ]);

        self::assertSame([], $errors);
    }

    #[Test]
    public function unparseable_dates_do_not_trigger_the_ordering_rule(): void
    {
        $errors = StatusAnnouncementValidator::validate([
            'state' => 'watching',
            'started_at' => 'not-a-date',
            'ended_at' => '2026-09-01 10:00',
        ]);

        self::assertSame([], $errors);
    }
}
