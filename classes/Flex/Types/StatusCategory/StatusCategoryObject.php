<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Flex\Types\StatusCategory;

use Grav\Common\Flex\FlexObject;
use Grav\Framework\Flex\FlexDirectory;

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
     * Derives `slug` from `title` at creation time, since the field is
     * always readonly/disabled in the form (see the blueprint) and Admin2
     * therefore never submits a value for it. `$key === ''` is exactly how
     * flex-objects' own create endpoint constructs a brand-new object
     * (`FlexDirectory::createObject($body, '')`); an object being loaded
     * from existing storage always arrives with its real key already set,
     * so this never runs again on a category that already exists.
     *
     * {@inheritdoc}
     */
    public function __construct(array $elements, $key, FlexDirectory $_flexDirectory, bool $validate = false)
    {
        if ($key === '' && !array_key_exists('slug', $elements) && !empty($elements['title'])) {
            $elements['slug'] = self::slugify((string) $elements['title']);
        }

        parent::__construct($elements, $key, $_flexDirectory, $validate);
    }

    /**
     * Lowercases, replaces runs of non-alphanumeric characters with a single
     * hyphen, and trims leading/trailing hyphens -- matches the blueprint's
     * own `[a-z0-9]+(-[a-z0-9]+)*` validation pattern. Falls back to a short
     * random suffix if the title contains no matchable characters at all
     * (e.g. pure punctuation or emoji), so a category can never end up with
     * an empty or invalid storage key.
     *
     * @param string $title
     * @return string
     */
    private static function slugify(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'category-' . substr(md5(uniqid('', true)), 0, 8);
    }

    /**
     * The blueprint's `slug` field doubles as the storage filename
     * (`storage.options.key: slug`), so on save the stored YAML content
     * never actually contains a `slug:` line -- it would be redundant with
     * the filename. That's fine for the stored data itself (Grav's own
     * storage layer re-derives it from the filename on a raw load), but it
     * also means the field is simply absent from a Twig-rendered form's
     * `getFormValue()` and from the raw element data `jsonSerialize()`
     * returns. Admin2 gets an existing category's values from
     * `jsonSerialize()` (via the Flex API's object-serialization
     * endpoint), not `getFormValue()`, so both are overridden here for the
     * two different consumers. Without this, editing an existing category
     * shows an empty Slug field instead of the category's actual machine
     * name.
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
     * {@inheritdoc}
     * @see self::getFormValue() for why `slug` needs restoring here too.
     */
    public function jsonSerialize()
    {
        $elements = parent::jsonSerialize();

        if ($this->exists()) {
            $elements['slug'] = $this->getKey();
        }

        return $elements;
    }

    /**
     * `slug` is permanently readonly/disabled in the blueprint (both add and
     * edit share the exact same schema in Admin2 -- there is no server-side
     * way to vary it between the two), so this is a backstop against any
     * caller that bypasses the form entirely (a raw Flex API PATCH, direct
     * object usage). Confirmed empirically: Grav's Flex storage does not
     * rename a file just because a field value changes on update -- the
     * `slug` field is already excluded from stored elements (see
     * `jsonSerialize()` above), so nothing ever reaches the storage layer's
     * key-change detection regardless. Silently ignoring the change here
     * keeps that behavior explicit and intentional rather than accidental.
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
