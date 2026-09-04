<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Flex\Types\StatusAnnouncement;

/**
 * Cross-field validation rules for status-announcements.
 *
 * The Admin2 form enforces per-field validation (required, select options,
 * etc.) declared in the blueprint, but two rules span more than one field
 * and cannot be expressed there:
 *
 *  - `ended_at` is required once `state` is `resolved`.
 *  - `ended_at` must not be earlier than `started_at`.
 *
 * This class has no dependency on Grav or Flex so it can be unit-tested
 * without a Grav bootstrap. It is called from StatusAnnouncementObject on
 * every update() so the rules hold for any write path (Admin2, the Flex API,
 * or direct Flex object usage), not only the admin form.
 */
final class StatusAnnouncementValidator
{
    public const ERROR_ENDED_AT_REQUIRED_WHEN_RESOLVED = 'ended_at is required when state is resolved.';
    public const ERROR_ENDED_AT_BEFORE_STARTED_AT = 'ended_at must not be earlier than started_at.';

    /**
     * @param array{state?: mixed, started_at?: mixed, ended_at?: mixed} $data
     * @return string[] Validation error messages. Empty when the data is valid.
     */
    public static function validate(array $data): array
    {
        $errors = [];

        $state = $data['state'] ?? null;
        $startedAt = self::toTimestamp($data['started_at'] ?? null);
        $endedAt = self::toTimestamp($data['ended_at'] ?? null);

        if ($state === 'resolved' && $endedAt === null) {
            $errors[] = self::ERROR_ENDED_AT_REQUIRED_WHEN_RESOLVED;
        }

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
