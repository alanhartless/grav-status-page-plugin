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
 * @property string $slug
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
     * The blueprint's `slug` field doubles as the storage filename
     * (`storage.options.key: slug`), so on save the stored YAML content
     * never actually contains a `slug:` line -- it would be redundant with
     * the filename. That's fine for the stored data itself (Grav's own
     * storage layer re-derives it from the filename on a raw load), but
     * the Admin2 edit form asks for field values through
     * `getFormValue()`, which reads plain element data and has no reason
     * to know this field is special. Without this override, editing an
     * existing category shows an empty Slug field instead of the
     * category's actual machine name.
     *
     * {@inheritdoc}
     */
    public function getFormValue(string $name, $default = null, ?string $separator = null)
    {
        if ($name === 'slug') {
            return $this->getKey();
        }

        return parent::getFormValue($name, $default, $separator);
    }

    /**
     * Locks the `slug` field once a category already exists.
     *
     * Grav's Flex storage never renames a file just because a field value
     * changed -- `updateRow()` always saves to the object's existing key,
     * and `renameRow()` is a separate operation nothing calls automatically
     * on a plain update. So without this, editing `slug` on a saved
     * category would appear to work (the form field updates, the save
     * succeeds) while doing nothing real: the file keeps its original
     * name, and every announcement referencing the old slug keeps pointing
     * at a value the category no longer claims to have. Locking the field
     * after creation avoids that silent mismatch entirely. `update()`
     * below is the server-side backstop in case anything ever bypasses
     * this form-level lock.
     *
     * {@inheritdoc}
     */
    public function getBlueprint(string $name = '')
    {
        $blueprint = parent::getBlueprint($name);

        if ($this->exists()) {
            $blueprint->set('form/fields/slug/readonly', true);
            $blueprint->set('form/fields/slug/disabled', true);
        }

        return $blueprint;
    }

    /**
     * Server-side backstop for the `slug` lock above -- ignores any
     * submitted change to `slug` on a category that already exists, rather
     * than trusting the disabled/readonly form fields to have been
     * respected by every caller (Admin2, the Flex API, direct usage).
     *
     * {@inheritdoc}
     */
    public function update(array $data, array $files = [])
    {
        if ($this->exists() && array_key_exists('slug', $data)) {
            unset($data['slug']);
        }

        return parent::update($data, $files);
    }
}
