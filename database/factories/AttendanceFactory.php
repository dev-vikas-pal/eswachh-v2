<?php

namespace Database\Factories;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'cleaner_id' => User::factory()->cleaner(),
            'worked_on' => Carbon::today(),
            'status' => AttendanceStatus::Present,
            'marked_at' => now(),
        ];
    }

    public function absent(): static
    {
        return $this->state(fn () => ['status' => AttendanceStatus::Absent]);
    }
}
