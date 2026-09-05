<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Flex\Types\StatusAnnouncement;

use DateTimeImmutable;
use Grav\Common\Data\ValidationException;
use Grav\Common\Flex\FlexObject;
use Grav\Common\Grav;
use Grav\Framework\Flex\FlexDirectory;
use Grav\Plugin\StatusPage\CategoryOptions;
use Grav\Plugin\StatusPage\Status\TimezoneResolver;

/**
 * An incident, maintenance, or informational post scoped to one or more
 * status-categories.
 *
 * `severity: none` is a deliberate, valid, default value -- a maintenance
 * notice or informational post must be publishable without coloring any day
 * on the history strip. Only `partial-outage` and `outage` feed the uptime
 * calculation.
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
     * Canonicalizes `categories` to machine keys on every construction, not
     * only on `update()`. flex-objects' own create endpoint
     * (`FlexDirectory::createObject($body, '')` followed directly by
     * `save()`) never calls `update()` at all -- confirmed by reading
     * `FlexApiController::create()` -- so a normalization that lives only
     * in `update()` silently never runs for a brand-new announcement, only
     * for one that's since been edited once. Running it here too, on every
     * load as well as every create, is safe: `normalizeToKeys()` is a
     * pure, idempotent pass-through for values that are already correct
     * keys, so this also transparently heals an already-broken stored
     * announcement's in-memory value on next read, with no explicit
     * re-save required.
     *
     * Also auto-fills `ended_at` with the current moment when an
     * announcement is created directly as `resolved` with no `ended_at` --
     * see `update()`'s docblock for why.
     *
     * {@inheritdoc}
     */
    public function __construct(array $elements, $key, FlexDirectory $_flexDirectory, bool $validate = false)
    {
        if (array_key_exists('categories', $elements)) {
            $elements['categories'] = CategoryOptions::normalizeToKeys($elements['categories'], CategoryOptions::options());
        }

        if (($elements['state'] ?? null) === 'resolved' && empty($elements['ended_at'] ?? null)) {
            $elements['ended_at'] = self::nowString();
        }

        parent::__construct($elements, $key, $_flexDirectory, $validate);
    }

    /**
     * Enforces the cross-field validation rules that the Admin2 form's
     * per-field `validate:` block cannot express on its own: `ended_at`
     * must not be earlier than `started_at`. Runs on every write
     * path -- Admin2, the Flex API, or direct Flex object usage -- not only
     * the admin form.
     *
     * `ended_at` being required once `state` is `resolved` used to be
     * enforced the same way (reject the write with a validation error), but
     * that produced a real, confirmed-bad UX: Admin2's edit-save error
     * handler discards the actual validation message for anything other
     * than a 409 conflict (`Yp()`'s update-page save handler in admin2's
     * compiled bundle only special-cases 409; everything else, including
     * ours, shows a hardcoded generic "Failed to save." toast) -- and there
     * is no cross-field "required when sibling field equals X" mechanism in
     * this Admin2 build to instead prevent the submission client-side in
     * the first place (checked: Grav core's own `condition:` blueprint
     * property is config-based only, never tied to another field's live
     * value). With no way to surface *why* the save failed and no way to
     * stop it before it's attempted, rejecting the write was a dead end --
     * marking something resolved with no explicit end date now means "it
     * just ended, right now" instead.
     *
     * Also canonicalizes `categories` to machine keys before anything else
     * sees it -- see CategoryOptions::normalizeToKeys() for why this is
     * necessary regardless of what the actual submission bug turns out to
     * be client-side.
     *
     * {@inheritdoc}
     */
    public function update(array $data, array $files = [])
    {
        if (array_key_exists('categories', $data)) {
            $data['categories'] = CategoryOptions::normalizeToKeys($data['categories'], CategoryOptions::options());
        }

        $merged = $this->getBlueprint()->mergeData($this->getElements(), $data);

        if (($merged['state'] ?? null) === 'resolved' && empty($merged['ended_at'] ?? null)) {
            $data['ended_at'] = self::nowString();
            $merged = $this->getBlueprint()->mergeData($this->getElements(), $data);
        }

        $errors = StatusAnnouncementValidator::validate($merged);
        if ($errors) {
            throw new ValidationException(implode(' ', $errors));
        }

        return parent::update($data, $files);
    }

    /**
     * "Now," formatted for storage in the same shape `started_at`/
     * `ended_at` already use, resolved in the same timezone the rest of
     * this plugin uses for "which moment is now" (`TimezoneResolver`,
     * matching `StatusPagePresenter`'s own resolution of
     * `plugins.status-page.timezone` -> `system.timezone` -> UTC) rather
     * than the server's raw default timezone.
     */
    private static function nowString(): string
    {
        $config = Grav::instance()['config'];
        $timezone = TimezoneResolver::resolve(
            (string) $config->get('plugins.status-page.timezone', ''),
            (string) $config->get('system.timezone', '')
        );

        return (new DateTimeImmutable('now', $timezone))->format('Y-m-d\TH:i:s');
    }

    /**
     * Display titles for this announcement's categories, safe against a
     * category having been deleted after this announcement referenced it --
     * a dangling key is silently dropped rather than fataling the caller
     * (typically the public status page template).
     *
     * @return array<string, string> key => title, dangling keys dropped.
     */
    public function getCategoryTitles(): array
    {
        $keys = (array) ($this->categories ?? []);

        return CategoryOptions::resolveTitles($keys, CategoryOptions::options());
    }

    /**
     * Workaround for a confirmed Admin2 bug: its categories field resolves
     * a selected chip's label via `optionsMap.get(storedValue) ?? storedValue`
     * (`Yp()` in admin2's compiled bundle), and that lookup misses for an
     * already-saved value even though the exact same map correctly labels
     * the dropdown suggestions -- a timing/reactivity gap in when Admin2
     * considers the options list ready, not something a blueprint can fix.
     *
     * The fallback (`?? storedValue`) means whatever raw value this method
     * hands back is what actually gets shown. Presenting titles here (e.g.
     * "Application") instead of keys ("application") makes that fallback
     * display correctly regardless of the timing bug. This never touches
     * how categories are actually stored or matched -- `getElements()`
     * (used by storage) and the `categories` property Twig/StatusProjector
     * read directly are untouched by this override, which only affects
     * what `jsonSerialize()` returns; `FlexApiController::serializeObject()`
     * is its one caller, and that's exactly the Admin2 API response this
     * workaround targets. Whatever Admin2 then submits back on save (title
     * or key) is already normalized to a real key by update()'s existing
     * `CategoryOptions::normalizeToKeys()` call, so persistence is
     * unaffected either way.
     *
     * {@inheritdoc}
     */
    public function jsonSerialize()
    {
        $elements = parent::jsonSerialize();

        if (array_key_exists('categories', $elements)) {
            $elements['categories'] = array_values(
                CategoryOptions::resolveTitles((array) $elements['categories'], CategoryOptions::options())
            );
        }

        return $elements;
    }
}
