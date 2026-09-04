<?php

declare(strict_types=1);

namespace Grav\Plugin\StatusPage\Tests\Status;

use Grav\Plugin\StatusPage\Status\OverallStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The banner's overall level -- stating plainly whether all categories are
 * currently operational -- is the worst of every category's own `current`
 * level -- same severity order StatusProjector uses (outage >
 * partial-outage > operational).
 */
final class OverallStatusTest extends TestCase
{
    #[Test]
    public function no_categories_is_vacuously_operational(): void
    {
        self::assertSame('operational', OverallStatus::fromCurrents([]));
    }

    #[Test]
    public function all_operational_is_operational(): void
    {
        self::assertSame('operational', OverallStatus::fromCurrents(['operational', 'operational']));
    }

    #[Test]
    public function one_partial_outage_among_operational_categories_is_partial_outage(): void
    {
        self::assertSame(
            'partial-outage',
            OverallStatus::fromCurrents(['operational', 'partial-outage', 'operational'])
        );
    }

    #[Test]
    public function one_outage_among_partial_outage_categories_is_outage(): void
    {
        self::assertSame(
            'outage',
            OverallStatus::fromCurrents(['partial-outage', 'outage', 'partial-outage'])
        );
    }

    #[Test]
    public function order_of_input_does_not_matter(): void
    {
        self::assertSame('outage', OverallStatus::fromCurrents(['outage', 'operational']));
        self::assertSame('outage', OverallStatus::fromCurrents(['operational', 'outage']));
    }

    #[Test]
    public function an_unrecognized_level_is_treated_as_operational_rather_than_fataling(): void
    {
        self::assertSame('operational', OverallStatus::fromCurrents(['not-a-real-level']));
    }

    #[Test]
    public function one_watching_among_operational_categories_is_watching(): void
    {
        self::assertSame(
            'watching',
            OverallStatus::fromCurrents(['operational', 'watching', 'operational'])
        );
    }

    #[Test]
    public function a_real_partial_outage_outranks_watching(): void
    {
        self::assertSame(
            'partial-outage',
            OverallStatus::fromCurrents(['watching', 'partial-outage'])
        );
    }

    #[Test]
    public function a_real_outage_outranks_watching(): void
    {
        self::assertSame('outage', OverallStatus::fromCurrents(['watching', 'outage']));
    }
}
