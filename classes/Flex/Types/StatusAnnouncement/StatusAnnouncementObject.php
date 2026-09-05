<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Flex\Types\StatusAnnouncement;

use DateTimeImmutable;
use DateTimeZone;
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
 * `started_at`/`ended_at` are always stored as UTC (an explicit `Z`-suffixed
 * ISO string, e.g. `2026-09-04T17:30:00Z`) -- confirmed live that Admin2's
 * datetime field submits a bare naive string with no timezone info at all
 * ('2026-09-04 13:00'), so the server has no way to know what timezone the
 * admin meant unless it's told. The admin's intended timezone is the
 * plugin's own configured one (`plugins.status-page.timezone`, same setting
 * already used for "which calendar day is today" on the history strip):
 * `__construct()`/`update()` interpret an incoming naive string as that
 * timezone and convert to UTC before it's ever merged or stored;
 * `jsonSerialize()` converts a stored UTC value back to that same timezone,
 * naive again, for Admin2 to display when populating the edit form -- so an
 * admin always sees and enters times in one consistent zone, round-tripping
 * correctly, while the public page (a separate, unauthenticated concern)
 * renders the stored UTC value with a `data-utc` attribute a small
 * client-side script converts to each visitor's own browser-local time
 * (`templates/partials/status-time.html.twig`).
 *
 * @property string $title
 * @property string|null $body
 * @property string $state active|watching|resolved
 * @property string $severity none|partial-outage|outage
 * @property string[] $categories
 * @property string $started_at UTC, e.g. '2026-09-04T17:30:00Z'.
 * @property string|null $ended_at UTC, same shape as $started_at.
 */
class StatusAnnouncementObject extends FlexObject
{
    private const UTC_FORMAT = 'Y-m-d\TH:i:s\Z';
    private const ADMIN_LOCAL_FORMAT = 'Y-m-d\TH:i:s';

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
     * Also converts a freshly-submitted `started_at`/`ended_at` from the
     * admin's configured timezone to UTC for storage -- see the class
     * docblock -- and auto-fills `ended_at` with the current UTC moment
     * when an announcement is created directly as `resolved` with no
     * `ended_at` -- see `update()`'s docblock for why.
     *
     * {@inheritdoc}
     */
    public function __construct(array $elements, $key, FlexDirectory $_flexDirectory, bool $validate = false)
    {
        if (array_key_exists('categories', $elements)) {
            $elements['categories'] = CategoryOptions::normalizeToKeys($elements['categories'], CategoryOptions::options());
        }

        foreach (['started_at', 'ended_at'] as $field) {
            if (!empty($elements[$field] ?? null)) {
                $elements[$field] = self::toUtc((string) $elements[$field]);
            }
        }

        if (($elements['state'] ?? null) === 'resolved' && empty($elements['ended_at'] ?? null)) {
            $elements['ended_at'] = self::nowUtcString();
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

        foreach (['started_at', 'ended_at'] as $field) {
            if (array_key_exists($field, $data) && !empty($data[$field])) {
                $data[$field] = self::toUtc((string) $data[$field]);
            }
        }

        $merged = $this->getBlueprint()->mergeData($this->getElements(), $data);

        if (($merged['state'] ?? null) === 'resolved' && empty($merged['ended_at'] ?? null)) {
            $data['ended_at'] = self::nowUtcString();
            $merged = $this->getBlueprint()->mergeData($this->getElements(), $data);
        }

        $errors = StatusAnnouncementValidator::validate($merged);
        if ($errors) {
            throw new ValidationException(implode(' ', $errors));
        }

        return parent::update($data, $files);
    }

    /**
     * The plugin's configured "admin timezone" -- what an admin's naive
     * `started_at`/`ended_at` input is interpreted as, and what a stored
     * UTC value is converted back to for display in the edit form. Same
     * resolution chain `StatusPagePresenter` uses for "which calendar day
     * is today" (`plugins.status-page.timezone` -> `system.timezone` ->
     * UTC), so there is exactly one site-wide notion of "what timezone does
     * this admin panel operate in," not a second one invented here.
     */
    private static function adminTimezone(): DateTimeZone
    {
        $config = Grav::instance()['config'];

        return TimezoneResolver::resolve(
            (string) $config->get('plugins.status-page.timezone', ''),
            (string) $config->get('system.timezone', '')
        );
    }

    /**
     * Converts a datetime string to UTC for storage. Safe to call on a
     * value that's already UTC-tagged (an explicit offset/`Z` suffix in the
     * string wins over the timezone passed to `DateTimeImmutable`'s
     * constructor, per PHP's own parsing rules) -- so this never
     * double-converts a value that was already stored correctly and is
     * merely passing through unchanged.
     */
    private static function toUtc(string $value): string
    {
        return (new DateTimeImmutable($value, self::adminTimezone()))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::UTC_FORMAT);
    }

    /**
     * Converts a stored UTC datetime string back to the admin's configured
     * timezone, naive (no offset), for Admin2 to display when populating
     * the edit form -- so an admin edits in the same zone they originally
     * entered a time in, not UTC.
     */
    private static function toAdminLocal(string $utcValue): string
    {
        return (new DateTimeImmutable($utcValue, new DateTimeZone('UTC')))
            ->setTimezone(self::adminTimezone())
            ->format(self::ADMIN_LOCAL_FORMAT);
    }

    /**
     * The current UTC moment, formatted for storage in the same shape
     * `started_at`/`ended_at` already use.
     */
    private static function nowUtcString(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::UTC_FORMAT);
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
     * Also converts stored UTC `started_at`/`ended_at` back to the admin's
     * configured timezone -- see the class docblock -- so Admin2's edit
     * form shows and re-submits times in the zone an admin actually
     * entered them in, not UTC.
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

        foreach (['started_at', 'ended_at'] as $field) {
            if (!empty($elements[$field] ?? null)) {
                $elements[$field] = self::toAdminLocal((string) $elements[$field]);
            }
        }

        return $elements;
    }
}
