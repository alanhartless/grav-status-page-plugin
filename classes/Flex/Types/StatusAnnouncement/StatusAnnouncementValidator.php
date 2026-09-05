<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Flex\Types\StatusAnnouncement;

/**
 * Cross-field validation rules for status-announcements.
 *
 * The Admin2 form enforces per-field validation (required, select options,
 * etc.) declared in the blueprint, but this one rule spans more than one
 * field and cannot be expressed there: `ended_at` must not be earlier than
 * `started_at`.
 *
 * `ended_at` used to also be required once `state` was `resolved`,
 * rejecting the write otherwise -- removed in favor of
 * `StatusAnnouncementObject` auto-filling `ended_at` with the current
 * moment when it's missing on a resolved announcement (see that class's
 * `update()` docblock for why: Admin2's edit-save error handler discards
 * the actual validation message for anything other than a 409, and there's
 * no cross-field "required when sibling field equals X" mechanism in this
 * Admin2 build to instead prevent the submission client-side). By the time
 * this validator runs from that call path, `ended_at` is therefore always
 * already filled whenever `state` is `resolved`, so a "required" rule here
 * would never fire.
 *
 * This class has no dependency on Grav or Flex so it can be unit-tested
 * without a Grav bootstrap. It is called from StatusAnnouncementObject on
 * every update() so the rule holds for any write path (Admin2, the Flex API,
 * or direct Flex object usage), not only the admin form.
 */
final class StatusAnnouncementValidator
{
    public const ERROR_ENDED_AT_BEFORE_STARTED_AT = 'ended_at must not be earlier than started_at.';

    /**
     * @param array{state?: mixed, started_at?: mixed, ended_at?: mixed} $data
     * @return string[] Validation error messages. Empty when the data is valid.
     */
    public static function validate(array $data): array
    {
        $errors = [];

        $startedAt = self::toTimestamp($data['started_at'] ?? null);
        $endedAt = self::toTimestamp($data['ended_at'] ?? null);

        if ($startedAt !== null && $endedAt !== null && $endedAt < $startedAt) {
            $errors[] = self::ERROR_ENDED_AT_BEFORE_STARTED_AT;
        }

        return $errors;
    }

    /**
     * @param mixed $value
     */
    private static function toTimestamp($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }
}
