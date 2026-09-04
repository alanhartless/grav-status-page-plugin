<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Flex\Types\StatusCategory;

use Grav\Common\Flex\FlexObject;

/**
 * A service category the status page groups incident announcements under.
 *
 * Deliberately has no status field and no manual status setter -- a
 * category's current status is derived entirely from its announcements.
 * The projection that computes it (`StatusProjector`) works over the plain
 * data these objects store.
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

    /**
     * The blueprint's `key` field doubles as the storage filename
     * (`storage.options.key: key`), so on save the stored YAML content
     * never actually contains a `key:` line -- it would be redundant with
     * the filename. That's fine for the stored data itself (Grav's own
     * storage layer re-derives it from the filename on a raw load), but
     * the Admin2 edit form asks for field values through
     * `getFormValue()`, which reads plain element data and has no reason
     * to know this field is special. Without this override, editing an
     * existing category shows an empty Key field instead of the category's
     * actual machine name.
     *
     * {@inheritdoc}
     */
    public function getFormValue(string $name, $default = null, ?string $separator = null)
    {
        if ($name === 'key') {
            return $this->getKey();
        }

        return parent::getFormValue($name, $default, $separator);
    }
}
