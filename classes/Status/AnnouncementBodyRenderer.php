<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Status;

use Rhukster\DomSanitizer\DOMSanitizer;

/**
 * Sanitizes markdown-rendered announcement-body HTML before it is marked
 * safe for Twig output (ISSUE-205.4 AC: "a `<script>` tag in a body does not
 * execute").
 *
 * Grav's own `|markdown` Twig filter (`Grav\Common\Utils::processMarkdown()`,
 * core) renders the authored Markdown to HTML and is itself marked
 * `is_safe: ['html']` -- it does not sanitize. This class is the sanitization
 * step: `rhukster/dom-sanitizer` is already a Grav-core runtime dependency
 * (used by `Grav\Common\Security::sanitizeSvgString()`), used here in its
 * `HTML` mode rather than `SVG` mode. `<script>` is not in that mode's
 * allowed-tag list, and inline event-handler attributes (`onerror`, ...)
 * and `javascript:` URLs are stripped by the same library.
 *
 * The plugin's own Twig filter registration (`status-page.php`) marks this
 * filter's own output `is_safe: ['html']` too, so the sanitized result is
 * not re-escaped on top of already being safe.
 */
final class AnnouncementBodyRenderer
{
    public static function sanitize(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $sanitizer = new DOMSanitizer(DOMSanitizer::HTML);
        $sanitized = $sanitizer->sanitize($html);

        return is_string($sanitized) ? $sanitized : '';
    }
}
