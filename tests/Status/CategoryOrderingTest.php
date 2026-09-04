<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests\Status;

use Grav\Plugin\StatusPage\Status\CategoryOrdering;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Category display order for the public page: `order` ascending, then
 * `title` to keep ties stable. Same rule `CategoryOptions::formatOptions()`
 * applies to the admin form's option list -- factored out here as its own
 * pure, tested function rather than re-deriving the sort inline in the
 * rendering glue.
 */
final class CategoryOrderingTest extends TestCase
{
    #[Test]
    public function orders_by_order_ascending(): void
    {
        $rows = [
            ['key' => 'web', 'title' => 'Web App', 'order' => 2],
            ['key' => 'api', 'title' => 'API', 'order' => 1],
        ];

        self::assertSame(['api', 'web'], CategoryOrdering::orderedKeys($rows));
    }

    #[Test]
    public function ties_on_order_break_by_title(): void
    {
        $rows = [
            ['key' => 'web', 'title' => 'Web App', 'order' => 0],
            ['key' => 'api', 'title' => 'API', 'order' => 0],
        ];

        self::assertSame(['api', 'web'], CategoryOrdering::orderedKeys($rows));
    }

    #[Test]
    public function empty_input_yields_empty_output(): void
    {
        self::assertSame([], CategoryOrdering::orderedKeys([]));
    }

    #[Test]
    public function missing_order_defaults_to_zero(): void
    {
        $rows = [
            ['key' => 'web', 'title' => 'Web App', 'order' => 5],
            ['key' => 'api', 'title' => 'API'],
        ];

        self::assertSame(['api', 'web'], CategoryOrdering::orderedKeys($rows));
    }
}
