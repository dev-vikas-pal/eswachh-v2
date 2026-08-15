<?php

namespace App\Domain\Pricing;

use App\Models\ClothBundle;
use App\Models\Duration;
use App\Models\Package;
use App\Models\ServiceType;
use App\Models\Society;
use App\Models\Subscription;
use App\Models\VehicleModel;
use App\Support\Tenancy\BranchContext;
use RuntimeException;

/**
 * Works out what a subscription costs.
 *
 * The price is assembled from five masters, exactly as v1 assembled it, so
 * historic amounts still reproduce:
 *
 *   (vehicle category + package + service type + society surcharge) × months
 *     − duration discount
 *     + cloth bundle
 *
 * The surcharge is per society because a basement in a tower block takes longer
 * to reach than a house on a lane. The discount is per duration because
 * committing to six months is worth something. The cloth bundle is added after
 * the multiplication because it is a one-off purchase, not a monthly charge.
 *
 * What is different from v1 is where the answer is trusted from. v1 sent this
 * calculation to the browser and then accepted whatever price the browser
 * posted back, checking only that it was at least one rupee - so a customer
 * could have paid ₹1 for any plan. Here nothing accepts a price from outside;
 * a caller names the masters and this decides what they cost.
 */
class PriceBook
{
    /**
     * Price a combination of masters.
     *
     * Everything is looked up fresh. A quote is a statement about the price
     * list as it stands right now, and must not be assembled from ids the
     * caller has already resolved to amounts.
     */
    public function quote(
        ?string $vehicleModelId,
        ?string $packageId,
        ?string $serviceTypeId,
        ?string $durationId,
        ?string $societyId = null,
        ?string $clothBundleId = null,
    ): Quote {
        /*
         * Masters are a shared catalogue, not branch property: every franchise
         * sells from the same price list. Read unscoped, deliberately - and
         * this is the only reason a quote works on the public site, where
         * there is no signed in user at all.
         */
        return BranchContext::withoutScope(function () use (
            $vehicleModelId, $packageId, $serviceTypeId, $durationId, $societyId, $clothBundleId
        ) {
            $duration = $durationId ? Duration::find($durationId) : null;

            if (! $duration) {
                // Without a duration there is nothing to multiply by, and
                // guessing at one month would quietly under-quote a customer
                // who picked six.
                throw new RuntimeException('A duration is needed before a price can be worked out.');
            }

            $months = max(1, (int) $duration->months);

            $lines = [];
            $warnings = [];
            $monthlyPaise = 0;

            /*
             * A master that was asked for and cannot be sold is recorded, not
             * ignored. Skipping it silently under-prices the plan by whatever
             * that master used to charge, and nobody would ever notice.
             *
             * Withdrawn and never-existed are told apart because they mean
             * different things to whoever has to sort it out: one is a plan
             * that needs moving onto a current package, the other is a broken
             * reference.
             */
            $missing = function (string $what, bool $withdrawn) use (&$warnings) {
                $warnings[] = $withdrawn
                    ? "The {$what} on this plan has been withdrawn from sale, so its price is not included."
                    : "The {$what} this plan refers to no longer exists, so its price is not included.";
            };

            // The car. Priced by its category, so every hatchback costs the
            // same whatever the badge on it says.
            if ($vehicleModelId) {
                $model = VehicleModel::with('category')->find($vehicleModelId);

                if (! $model?->category) {
                    $missing('vehicle type', VehicleModel::withTrashed()->whereKey($vehicleModelId)->exists());
                } else {
                    $price = (int) $model->category->price_paise;
                    $monthlyPaise += $price;

                    $lines[] = new QuoteLine(
                        'category',
                        $model->category->name.' vehicle',
                        $price,
                        $model->category->id,
                    );
                }
            }

            if ($packageId) {
                if ($package = Package::find($packageId)) {
                    $monthlyPaise += (int) $package->price_paise;
                    $lines[] = new QuoteLine('package', $package->name, (int) $package->price_paise, $package->id);
                } else {
                    $missing('package', Package::withTrashed()->whereKey($packageId)->exists());
                }
            }

            if ($serviceTypeId) {
                if ($serviceType = ServiceType::find($serviceTypeId)) {
                    $monthlyPaise += (int) $serviceType->price_paise;
                    $lines[] = new QuoteLine(
                        'service_type',
                        'Interior cleaning: '.$serviceType->name,
                        (int) $serviceType->price_paise,
                        $serviceType->id,
                    );
                } else {
                    $missing('interior cleaning option', ServiceType::withTrashed()->whereKey($serviceTypeId)->exists());
                }
            }

            // Harder-to-reach addresses cost more to service.
            if ($societyId) {
                if ($society = Society::find($societyId)) {
                    $surcharge = (int) $society->surcharge_paise;

                    if ($surcharge !== 0) {
                        $monthlyPaise += $surcharge;
                        $lines[] = new QuoteLine('society', $society->name.' surcharge', $surcharge, $society->id);
                    }
                } else {
                    $missing('society', Society::withTrashed()->whereKey($societyId)->exists());
                }
            }

            $subtotalPaise = $monthlyPaise * $months;

            // Charged once, not per month, so it sits outside the multiplication.
            $discountPaise = (int) $duration->discount_paise;

            $lines[] = new QuoteLine(
                'duration',
                $duration->name,
                -$discountPaise,
                $duration->id,
                recurring: false,
            );

            $clothPaise = 0;

            if ($clothBundleId) {
                if ($bundle = ClothBundle::find($clothBundleId)) {
                    $clothPaise = (int) $bundle->price_paise;
                    $lines[] = new QuoteLine('cloth', $bundle->name, $clothPaise, $bundle->id, recurring: false);
                } else {
                    $missing('cloth bundle', ClothBundle::withTrashed()->whereKey($clothBundleId)->exists());
                }
            }

            $totalPaise = $subtotalPaise - $discountPaise + $clothPaise;

            if ($totalPaise < 0) {
                // A discount larger than the plan it applies to means somebody
                // mistyped a master. Better to refuse than to invoice a
                // negative amount or silently clamp it to zero.
                throw new RuntimeException(
                    'The discount on this duration is larger than the plan it applies to. Check the price list.'
                );
            }

            return new Quote(
                lines: $lines,
                subtotalPaise: $subtotalPaise,
                discountPaise: $discountPaise,
                clothPaise: $clothPaise,
                totalPaise: $totalPaise,
                months: $months,
                warnings: $warnings,
            );
        });
    }

    /**
     * Re-price an existing subscription against today's price list.
     *
     * Used at renewal. The answer can differ from what was originally paid, and
     * that is correct - a renewal is bought at today's prices, not at the price
     * a master happened to have two years ago.
     */
    public function forRenewal(Subscription $subscription): Quote
    {
        $vehicle = $subscription->vehicle;
        $customer = $subscription->customer;

        return $this->quote(
            $vehicle?->vehicle_model_id,
            $subscription->package_id,
            $subscription->service_type_id,
            $subscription->duration_id,
            $customer?->society_id,
            $subscription->cloth_service ? $subscription->cloth_bundle_id : null,
        );
    }
}
