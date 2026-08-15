<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A customer's cars.
 *
 * Managed from the customer screen rather than as a list of their own: nobody
 * looks for a car, they look for the person who owns it.
 *
 * The registration is the identity here. It is what a customer quotes on the
 * phone, what a cleaner reads off the boot, and what the round is built from -
 * so it is normalised and kept unique among live records.
 */
class VehicleController extends Controller
{
    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('create.vehicle');

        $data = $this->validated($request, $customer);

        $vehicle = Vehicle::create($data + [
            // Follows the customer, so a car cannot end up filed against a
            // branch that does not go to the address it is parked at.
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'status' => true,
        ]);

        return response()->json(['data' => $this->present($vehicle)], 201);
    }

    public function update(Request $request, Customer $customer, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update.vehicle');
        $this->assertBelongsTo($vehicle, $customer);

        $vehicle->update($this->validated($request, $customer, $vehicle));

        return response()->json(['data' => $this->present($vehicle->fresh())]);
    }

    /**
     * Take a car off the books.
     *
     * Refused while a plan is running against it. Removing a car somebody is
     * paying for would drop it off the round with the money still coming in,
     * and nobody would notice until the customer rang.
     */
    public function destroy(Customer $customer, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update.vehicle');
        $this->assertBelongsTo($vehicle, $customer);

        $live = $vehicle->subscriptions()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Pending])
            ->count();

        if ($live > 0) {
            throw ValidationException::withMessages([
                'vehicle' => "That car has {$live} plan(s) still running. End or pause them first.",
            ]);
        }

        $vehicle->delete();

        return response()->json(['message' => 'Car removed. Its service history is kept.']);
    }

    /**
     * Put a cleaner on this car directly.
     */
    public function assignCleaner(Request $request, Customer $customer, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('assign.cleaner');
        $this->assertBelongsTo($vehicle, $customer);

        $data = $request->validate([
            'cleaner_id' => ['present', 'nullable', 'string', 'exists:users,id'],
        ]);

        $cleaner = null;

        if ($data['cleaner_id']) {
            $cleaner = User::query()->visible()->findOrFail($data['cleaner_id']);

            if ($cleaner->role !== UserRole::Cleaner || $cleaner->branch_id !== $vehicle->branch_id) {
                throw ValidationException::withMessages([
                    'cleaner_id' => 'That person cannot clean this car.',
                ]);
            }
        }

        $vehicle->forceFill(['assigned_cleaner_id' => $cleaner?->id])->save();

        return response()->json([
            'message' => $cleaner ? "{$cleaner->name} will clean {$vehicle->registration}." : 'Cleaner removed.',
        ]);
    }

    // --------------------------------------------------------------- private

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Customer $customer, ?Vehicle $existing = null): array
    {
        $sometimes = $existing ? 'sometimes' : 'required';

        $data = $request->validate([
            'registration' => [
                $sometimes, 'string', 'max:20',
                // Spaces and case vary wildly in how people type a plate, and
                // the model normalises on write - so uniqueness is checked
                // against the normalised form, not what was typed.
                Rule::unique('vehicles', 'registration')
                    ->ignore($existing?->id)
                    ->whereNull('deleted_at'),
            ],
            'vehicle_model_id' => ['nullable', 'string', 'exists:vehicle_models,id'],
            'assigned_cleaner_id' => ['nullable', 'string', 'exists:users,id'],
            'status' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['registration'])) {
            $data['registration'] = strtoupper(preg_replace('/\s+/', '', $data['registration']));

            // Re-checked after normalising: "UP 16 AB 1234" and "UP16AB1234"
            // are the same car, and the rule above would let both through.
            $clash = Vehicle::withoutGlobalScopes()
                ->where('registration', $data['registration'])
                ->whereNull('deleted_at')
                ->when($existing, fn ($q) => $q->whereKeyNot($existing->id))
                ->first();

            if ($clash) {
                throw ValidationException::withMessages([
                    'registration' => $clash->customer_id === $customer->id
                        ? 'That car is already on this customer.'
                        : 'That car number is already registered to another customer.',
                ]);
            }
        }

        return $data;
    }

    private function assertBelongsTo(Vehicle $vehicle, Customer $customer): void
    {
        // 404 rather than 403: the car exists, but not for this customer, and
        // confirming otherwise says something about somebody else's records.
        abort_unless($vehicle->customer_id === $customer->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Vehicle $vehicle): array
    {
        $vehicle->loadMissing('cleaner:id,name', 'model:id,name');

        return [
            'id' => $vehicle->id,
            'registration' => $vehicle->registration,
            'model' => $vehicle->model?->name,
            'vehicle_model_id' => $vehicle->vehicle_model_id,
            'cleaner' => $vehicle->cleaner?->name,
            'assigned_cleaner_id' => $vehicle->assigned_cleaner_id,
            'status' => (bool) $vehicle->status,
        ];
    }
}
