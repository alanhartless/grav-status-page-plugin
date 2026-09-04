<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests\Status;

use Grav\Plugin\StatusPage\Status\AnnouncementBodyRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The XSS boundary for announcement bodies: markdown is rendered to HTML
 * by Grav's own `|markdown` Twig filter (core, untested
 * here -- rendering Markdown itself needs a live Grav container, matching
 * the same "Grav-coupled work stays at the call site" split as
 * TimezoneResolver). This class is the one piece of that pipeline that is
 * both pure (no Grav dependency -- `rhukster/dom-sanitizer` is a plain
 * Composer package) and actually security-relevant: it is what a `<script>`
 * tag in an authored body has to get through.
 */
final class AnnouncementBodyRendererTest extends TestCase
{
    #[Test]
    public function strips_a_script_tag_entirely(): void
    {
        $html = AnnouncementBodyRenderer::sanitize('<p>Hello</p><script>alert(1)</script>');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('alert(1)', $html);
        self::assertStringContainsString('Hello', $html);
    }

    #[Test]
    public function strips_an_inline_event_handler_attribute(): void
    {
        $html = AnnouncementBodyRenderer::sanitize('<img src="x" onerror="alert(1)">');

        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    #[Test]
    public function strips_a_javascript_protocol_href(): void
    {
        $html = AnnouncementBodyRenderer::sanitize('<a href="javascript:alert(1)">click</a>');

        self::assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    public function preserves_ordinary_markdown_generated_markup(): void
    {
        $html = AnnouncementBodyRenderer::sanitize('<p>We are investigating <strong>increased latency</strong>.</p>');

        self::assertStringContainsString('<p>', $html);
        self::assertStringContainsString('<strong>increased latency</strong>', $html);
    }

    #[Test]
    public function empty_body_yields_empty_output(): void
    {
        self::assertSame('', trim(AnnouncementBodyRenderer::sanitize('')));
    }
}
