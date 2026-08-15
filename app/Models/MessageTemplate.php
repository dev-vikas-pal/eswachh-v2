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
    public static function valuesFor(Subscription $subscription): array
    {
        return [
            'name' => $subscription->customer?->name ?? 'there',
            'car' => $subscription->vehicle?->registration ?? 'your car',
            'amount' => number_format($subscription->amount(), 0),
            'renew_date' => $subscription->period_end?->format('j M Y') ?? '',
            'cloths' => (string) ($subscription->cloth_balance ?? 0),
            'business' => (string) \App\Support\Settings\SiteSettings::get('business_name', 'Eswachh'),
            'phone' => (string) \App\Support\Settings\SiteSettings::get('contact_phone', ''),
        ];
    }

    /** The placeholders any template may use, for the editor to list. */
    public static function availablePlaceholders(): array
    {
        return ['name', 'car', 'amount', 'renew_date', 'cloths', 'business', 'phone'];
    }
}
