<?php

namespace Database\Factories;

use App\Models\Duration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Duration>
 */
class DurationFactory extends Factory
{
    protected $model = Duration::class;

    public function definition(): array
    {
        return [
            'name' => '1 Month',
            'months' => 1,
            'discount_paise' => 0,
            'status' => true,
        ];
    }

    public function months(int $months): static
    {
        return $this->state(fn () => [
            'name' => $months.' Month'.($months === 1 ? '' : 's'),
            'months' => $months,
        ]);
    }
}
