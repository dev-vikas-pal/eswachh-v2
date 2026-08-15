<?php

namespace Tests\Feature\Pricing;

use App\Domain\Pricing\PriceBook;
use App\Models\ClothBundle;
use App\Models\Duration;
use App\Models\Package;
use App\Models\Sector;
use App\Models\ServiceType;
use App\Models\Society;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * A price is assembled from five masters, and the arithmetic has to match v1's
 * exactly or every renewal quote changes on the day of the switch.
 *
 *   (category + package + service type + society) × months − discount + cloth
 */
class PriceBookTest extends TestCase
{
    use RefreshDatabase;

    private PriceBook $book;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();
        $this->book = app(PriceBook::class);
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    public function test_a_single_month_adds_the_masters_together(): void
    {
        $ids = $this->catalogue(categoryPaise: 59900, packagePaise: 0, servicePaise: 10000, months: 1);

        $quote = $this->book->quote($ids['model'], $ids['package'], $ids['service'], $ids['duration']);

        $this->assertSame(69900, $quote->totalPaise);
        $this->assertSame(699.0, $quote->total());
    }

    public function test_the_monthly_parts_are_multiplied_and_the_discount_is_not(): void
    {
        $ids = $this->catalogue(
            categoryPaise: 59900, packagePaise: 0, servicePaise: 10000,
            months: 3, discountPaise: 7500,
        );

        // (599 + 0 + 100) × 3 = 2097, less a flat 75 discount.
        $quote = $this->book->quote($ids['model'], $ids['package'], $ids['service'], $ids['duration']);

        $this->assertSame(209700, $quote->subtotalPaise);
        $this->assertSame(7500, $quote->discountPaise);
        $this->assertSame(202200, $quote->totalPaise);
    }

    public function test_the_society_surcharge_is_charged_every_month(): void
    {
        $ids = $this->catalogue(categoryPaise: 50000, packagePaise: 0, servicePaise: 0, months: 6);

        $society = Society::create([
            'sector_id' => Sector::factory()->create()->id,
            'name' => 'Tower Block',
            'surcharge_paise' => 5000,
            'status' => true,
        ]);

        $quote = $this->book->quote(
            $ids['model'], $ids['package'], $ids['service'], $ids['duration'], $society->id
        );

        // A harder address costs more for every month it is serviced, not once.
        $this->assertSame(330000, $quote->totalPaise);
    }

    public function test_a_cloth_bundle_is_charged_once_however_long_the_plan(): void
    {
        $ids = $this->catalogue(categoryPaise: 50000, packagePaise: 0, servicePaise: 0, months: 6);
        $bundle = ClothBundle::create([
            'name' => '100 Cloths', 'cloth_count' => 100, 'price_paise' => 80000, 'status' => true,
        ]);

        $quote = $this->book->quote(
            $ids['model'], $ids['package'], $ids['service'], $ids['duration'], null, $bundle->id
        );

        // 500 × 6 = 3000, plus one bundle at 800. Not six bundles.
        $this->assertSame(380000, $quote->totalPaise);
        $this->assertSame(80000, $quote->clothPaise);
    }

    public function test_the_quote_itemises_what_it_charged_for(): void
    {
        $ids = $this->catalogue(categoryPaise: 59900, packagePaise: 5000, servicePaise: 10000, months: 1);

        $quote = $this->book->quote($ids['model'], $ids['package'], $ids['service'], $ids['duration']);

        // "Why is this ₹749?" has to have an answer, or nobody can explain a
        // renewal that changed because a master was edited.
        $sources = array_column(array_map(fn ($l) => $l->toArray(), $quote->lines), 'source');

        $this->assertSame(['category', 'package', 'service_type', 'duration'], $sources);
        $this->assertSame(59900, $quote->lines[0]->amountPaise);
    }

    public function test_a_withdrawn_master_is_reported_rather_than_silently_dropped(): void
    {
        $ids = $this->catalogue(categoryPaise: 59900, packagePaise: 10000, servicePaise: 0, months: 1);

        Package::findOrFail($ids['package'])->delete();

        $quote = $this->book->quote($ids['model'], $ids['package'], $ids['service'], $ids['duration']);

        // The plan is now ₹100 cheaper than it was. Saying nothing about that
        // is how a price quietly drops and nobody notices.
        $this->assertFalse($quote->isComplete());
        $this->assertStringContainsString('withdrawn', $quote->warnings[0]);
        $this->assertSame(59900, $quote->totalPaise);
    }

    public function test_a_reference_to_nothing_reads_differently_from_a_withdrawal(): void
    {
        $ids = $this->catalogue(categoryPaise: 59900, packagePaise: 0, servicePaise: 0, months: 1);

        $quote = $this->book->quote(
            $ids['model'], (string) \Illuminate\Support\Str::uuid7(), $ids['service'], $ids['duration']
        );

        // One needs the plan moved onto a current package; the other is a
        // broken reference. Different problems, different messages.
        $this->assertStringContainsString('no longer exists', $quote->warnings[0]);
    }

    public function test_a_plan_with_no_duration_is_refused(): void
    {
        $ids = $this->catalogue(categoryPaise: 59900, packagePaise: 0, servicePaise: 0, months: 1);

        $this->expectException(RuntimeException::class);

        // Quietly assuming one month would under-quote somebody who chose six.
        $this->book->quote($ids['model'], $ids['package'], $ids['service'], null);
    }

    public function test_a_discount_larger_than_the_plan_is_refused(): void
    {
        $ids = $this->catalogue(
            categoryPaise: 10000, packagePaise: 0, servicePaise: 0,
            months: 1, discountPaise: 50000,
        );

        $this->expectException(RuntimeException::class);

        // A mistyped master should stop the sale, not invoice a negative amount
        // or quietly clamp to zero and give the plan away.
        $this->book->quote($ids['model'], $ids['package'], $ids['service'], $ids['duration']);
    }

    public function test_the_per_month_figure_lets_plans_be_compared(): void
    {
        $ids = $this->catalogue(
            categoryPaise: 50000, packagePaise: 0, servicePaise: 0,
            months: 6, discountPaise: 30000,
        );

        $quote = $this->book->quote($ids['model'], $ids['package'], $ids['service'], $ids['duration']);

        // 3000 − 300 = 2700 over six months, so 450 a month.
        $this->assertSame(45000, $quote->perMonthPaise());
    }

    /**
     * @return array{model: string, package: string, service: string, duration: string}
     */
    private function catalogue(
        int $categoryPaise,
        int $packagePaise,
        int $servicePaise,
        int $months,
        int $discountPaise = 0,
    ): array {
        $category = VehicleCategory::create([
            'name' => 'Hatchback', 'price_paise' => $categoryPaise, 'status' => true,
        ]);

        return [
            'model' => VehicleModel::create([
                'vehicle_category_id' => $category->id, 'name' => 'Swift', 'status' => true,
            ])->id,
            'package' => Package::create([
                'name' => 'Basic', 'price_paise' => $packagePaise, 'status' => true,
            ])->id,
            'service' => ServiceType::create([
                'name' => 'Weekly', 'price_paise' => $servicePaise, 'status' => true,
            ])->id,
            'duration' => Duration::create([
                'name' => $months.' Months', 'months' => $months,
                'discount_paise' => $discountPaise, 'status' => true,
            ])->id,
        ];
    }
}
