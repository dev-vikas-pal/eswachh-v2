<?php

namespace Database\Factories;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPriority;
use App\Enums\ComplaintStatus;
use App\Models\Branch;
use App\Models\Complaint;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Complaint>
 */
class ComplaintFactory extends Factory
{
    protected $model = Complaint::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(),
            // Unique without going through the numbering series, which needs a
            // real branch row and a lock.
            'reference' => 'TST/CMP/2026-27/'.Str::upper(Str::random(8)),
            'category' => ComplaintCategory::NotCleaned,
            'priority' => ComplaintPriority::Normal,
            'description' => fake()->sentence(12),
            'status' => ComplaintStatus::Open,
            'due_at' => now()->addHours(8),
        ];
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn () => [
            'customer_id' => $customer->id,
            'branch_id' => $customer->branch_id,
        ]);
    }

    /** Past its promised time and still nobody's answer. */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'status' => ComplaintStatus::Open,
            'due_at' => now()->subHours(3),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => [
            'status' => ComplaintStatus::Closed,
            'resolved_at' => now()->subDay(),
            'closed_at' => now(),
        ]);
    }
}
