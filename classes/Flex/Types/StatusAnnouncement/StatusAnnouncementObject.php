<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Flex\Types\StatusAnnouncement;

use Grav\Common\Data\ValidationException;
use Grav\Common\Flex\FlexObject;
use Grav\Plugin\StatusPage\CategoryOptions;

/**
 * An incident, maintenance, or informational post scoped to one or more
 * status-categories.
 *
 * `severity: none` is a deliberate, valid, default value -- a maintenance
 * notice or informational post must be publishable without coloring any day
 * on the history strip. Only `partial-outage` and `outage` feed the uptime
 * calculation (ISSUE-205.3).
 *
 * @property string $title
 * @property string|null $body
 * @property string $state active|watching|resolved
 * @property string $severity none|partial-outage|outage
 * @property string[] $categories
 * @property string $started_at
 * @property string|null $ended_at
 */
class StatusAnnouncementObject extends FlexObject
{
    /**
     * Enforces the cross-field validation rules that the Admin2 form's
     * per-field `validate:` block cannot express on its own (EPIC-205
     * ISSUE-205.2): `ended_at` is required once `state` is `resolved`, and
     * `ended_at` must not be earlier than `started_at`. Runs on every write
     * path -- Admin2, the Flex API, or direct Flex object usage -- not only
     * the admin form.
     *
     * {@inheritdoc}
     */
    public function update(array $data, array $files = [])
    {
        $merged = array_replace($this->toArray(), $data);

        $errors = StatusAnnouncementValidator::validate($merged);
        if ($errors) {
            throw new ValidationException(implode(' ', $errors));
        }

        return parent::update($data, $files);
    }

    /**
     * Display titles for this announcement's categories, safe against a
     * category having been deleted after this announcement referenced it --
     * a dangling key is silently dropped rather than fataling the caller
     * (typically the public status page template, ISSUE-205.4).
     *
     * @return array<string, string> key => title, dangling keys dropped.
     */
    public function getCategoryTitles(): array
    {
        $keys = (array) ($this->categories ?? []);

        return CategoryOptions::resolveTitles($keys, CategoryOptions::options());
    }
}
