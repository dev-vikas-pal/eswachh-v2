<?php

namespace App\Models;

use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;

/**
 * The wording of one message.
 *
 * Rendered here rather than at each call site, so a template edited in the
 * office changes what the nightly job sends as well as what the bulk send does.
 */
class MessageTemplate extends BaseModel
{
    protected $attributes = [
        'channel' => 'whatsapp',
        'bulk_sendable' => false,
        'status' => true,
    ];

    protected $fillable = [
        'key', 'name', 'description', 'channel',
        'provider_template', 'body', 'placeholders', 'bulk_sendable', 'status',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'placeholders' => 'array',
            'bulk_sendable' => 'boolean',
            'status' => 'boolean',
        ]);
    }

    /** Templates the office may pick from when messaging selected rows. */
    public function scopeBulkSendable(Builder $query): Builder
    {
        return $query->where('status', true)->where('bulk_sendable', true)->orderBy('name');
    }

    /**
     * Fill the placeholders in.
     *
     * An unknown placeholder is left exactly as written rather than blanked:
     * a customer receiving "Renew for Rs {amount}" is obviously broken and gets
     * reported, while one receiving "Renew for Rs " looks almost right and does
     * not.
     *
     * @param  array<string, string|int|float|null>  $values
     */
    public function render(array $values): string
    {
        $body = $this->body;

        foreach ($values as $key => $value) {
            if ($value === null) {
                continue;
            }

            $body = str_replace('{'.$key.'}', (string) $value, $body);
        }

        return $body;
    }

    /**
     * The values every template can use, taken from a subscription.
     *
     * One place, so a template author can rely on {car} meaning the same thing
     * wherever it appears.
     *
     * @return array<string, string>
     */
    /**
     * @param  array<string, string>  $extra  Values only the caller knows
     * @return array<string, string>
     */
    public static function valuesFor(Subscription $subscription, array $extra = []): array
    {
        /*
         * Loaded here rather than left to each caller.
         *
         * This method reaches for five relations, and lazy loading is switched
         * off outside production - so a caller that had not thought to load one
         * would throw instead of sending. Putting it in the one place that
         * reads them means every sender, present and future, is safe.
         */
        $subscription->loadMissing([
            'customer', 'vehicle.cleaner', 'package', 'duration', 'clothBundle',
        ]);

        $cleaner = $subscription->vehicle?->cleaner;

        return array_merge([
            'name' => $subscription->customer?->name ?? 'there',
            'car' => $subscription->vehicle?->registration ?? 'your car',
            'amount' => number_format($subscription->amount(), 0),
            'renew_date' => $subscription->period_end?->format('j M Y') ?? '',
            'cloths' => (string) ($subscription->cloth_balance ?? 0),
            'business' => (string) \App\Support\Settings\SiteSettings::get('business_name', 'Eswachh'),
            'phone' => (string) \App\Support\Settings\SiteSettings::get('contact_phone', ''),

            /*
             * Where to sign in. Read from the configured URL rather than
             * written into the wording, so a template edited in the office
             * cannot end up pointing at the wrong site.
             */
            'site' => rtrim((string) config('app.url'), '/'),

            'package' => $subscription->package?->name ?? 'cleaning',
            'months' => (string) ($subscription->duration?->months ?? ''),
            // Reads as a sentence either way: "yes - Weekly 20" or "no".
            'cloth_plan' => $subscription->cloth_service
                ? 'yes - '.($subscription->clothBundle?->name ?? 'cloth plan')
                : 'no',
            'cleaner' => $cleaner?->name ?? '',
            'cleaner_phone' => $cleaner?->phone ?? '',
            'date' => now()->format('j M Y'),
            // Filled by whoever is sending: a pickup count, a delivery count.
            'count' => '',
            'message' => '',

            /*
             * The receipt's own figures, blank unless a payment supplied them.
             *
             * Deliberately not defaulted from the plan. `amount` above is what
             * the plan costs today; a receipt has to say what was taken, when,
             * and under which invoice number - and quietly substituting the
             * plan's price would produce a receipt that is wrong in exactly the
             * way nobody checks.
             */
            'invoice_number' => '',
            'invoice_link' => '',
            'paid_amount' => '',
            'paid_on' => '',
            'method' => '',
        ], $extra);
    }

    /** The placeholders any template may use, for the editor to list. */
    public static function availablePlaceholders(): array
    {
        return [
            'name', 'car', 'amount', 'renew_date', 'cloths', 'business', 'phone',
            'package', 'months', 'cloth_plan', 'cleaner', 'cleaner_phone',
            'date', 'count', 'message',
            // Only filled on the receipt, which is the only message sent from a
            // payment rather than from a plan.
            'invoice_number', 'invoice_link', 'paid_amount', 'paid_on', 'method',
        ];
    }
}
