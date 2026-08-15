<?php

namespace Database\Factories;

use App\Enums\ServiceOutcome;
use App\Models\Branch;
use App\Models\ServiceLog;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ServiceLog>
 */
class ServiceLogFactory extends Factory
{
    protected $model = ServiceLog::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'vehicle_id' => Vehicle::factory(),
            'serviced_on' => Carbon::today(),
            'serviced_at' => now(),
            'outcome' => ServiceOutcome::Cleaned,
        ];
    }

    public function outcome(ServiceOutcome $outcome): static
    {
        return $this->state(fn () => ['outcome' => $outcome]);
    }
}
