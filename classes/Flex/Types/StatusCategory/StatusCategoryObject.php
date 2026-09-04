<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Flex\Types\StatusCategory;

use Grav\Common\Flex\FlexObject;

/**
 * A service category the status page groups incident announcements under.
 *
 * Deliberately has no status field and no manual status setter -- a
 * category's current status is derived entirely from its announcements
 * (EPIC-205 PLAN.md decision D6). The projection that computes it lives in
 * ISSUE-205.3, over the plain data these objects store.
 *
 * @property string $key
 * @property string $title
 * @property string|null $description
 * @property int $order
 */
class StatusCategoryObject extends FlexObject
{
    /**
     * @return string
     */
    public function getTitle(): string
    {
        return (string) ($this->title ?? $this->getKey());
    }
}
