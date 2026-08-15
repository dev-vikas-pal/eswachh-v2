<?php

namespace App\Domain\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Talks to Razorpay over plain HTTP.
 *
 * Deliberately not the vendor SDK. The SDK throws its own exception hierarchy,
 * cannot be faked in a test without a network, and pins a Guzzle version; a
 * handful of HTTP calls behind Laravel's client is smaller, testable with
 * Http::fake(), and leaves nothing to upgrade.
 *
 * When the gateway is switched off - local and test - orders are simulated so
 * the whole payment path can be exercised end to end without money moving.
 */
class RazorpayGateway
{
    public function isLive(): bool
    {
        return (bool) config('services.razorpay.enabled')
            && config('services.razorpay.key')
            && config('services.razorpay.secret');
    }

    /**
     * Ask Razorpay for an order to send the customer to.
     *
     * @param  array<string, string>  $notes  Echoed back on the payment; useful when reconciling
     * @return array{id: string, amount: int, currency: string, simulated: bool}
     */
    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        if ($amountPaise < 100) {
            // Razorpay rejects anything under a rupee, and a zero rupee order
            // usually means a pricing bug upstream. Fail here where the cause
            // is obvious rather than on a gateway error page.
            throw new RuntimeException('A payment must be at least one rupee.');
        }

        if (! $this->isLive()) {
            $id = 'order_sim_'.Str::lower(Str::random(14));

            Log::info('Razorpay is switched off; simulating an order.', [
                'order_id' => $id,
                'amount_paise' => $amountPaise,
                'receipt' => $receipt,
            ]);

            return ['id' => $id, 'amount' => $amountPaise, 'currency' => 'INR', 'simulated' => true];
        }

        $response = $this->client()->post('/orders', [
            'amount' => $amountPaise,
            'currency' => 'INR',
            // Our own payment id. Razorpay shows it in their dashboard, which
            // is what makes a disputed charge traceable back to a row here.
            'receipt' => $receipt,
            'notes' => $notes,
            'payment_capture' => 1,
        ]);

        if ($response->failed()) {
            Log::error('Razorpay refused to create an order.', [
                'status' => $response->status(),
                'body' => $response->json(),
                'receipt' => $receipt,
            ]);

            throw new RuntimeException('The payment provider is not responding. Please try again shortly.');
        }

        return [
            'id' => $response->json('id'),
            'amount' => (int) $response->json('amount'),
            'currency' => $response->json('currency', 'INR'),
            'simulated' => false,
        ];
    }

    /**
     * Read a payment back from the gateway.
     *
     * The callback tells us what the browser was told. This tells us what
     * Razorpay actually holds, which is the version that settles disputes and
     * the one reconciliation compares against.
     *
     * @return array<string, mixed>|null
     */
    public function fetchPayment(string $gatewayPaymentId): ?array
    {
        if (! $this->isLive()) {
            return [
                'id' => $gatewayPaymentId,
                'status' => 'captured',
                'method' => 'simulated',
                'reference' => null,
            ];
        }

        $response = $this->client()->get('/payments/'.$gatewayPaymentId);

        if ($response->failed()) {
            Log::warning('Could not read a payment back from Razorpay.', [
                'gateway_payment_id' => $gatewayPaymentId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json();

        return [
            'id' => $body['id'] ?? $gatewayPaymentId,
            'status' => $body['status'] ?? null,
            'method' => $body['method'] ?? null,
            'amount' => isset($body['amount']) ? (int) $body['amount'] : null,
            // Whichever reference the method gives us, so a bank statement can
            // be matched without opening the Razorpay dashboard.
            'reference' => $body['acquirer_data']['upi_transaction_id']
                ?? $body['acquirer_data']['bank_transaction_id']
                ?? $body['acquirer_data']['rrn']
                ?? null,
            'raw' => $body,
        ];
    }

    /**
     * Every charge Razorpay holds against one of our orders.
     *
     * This is what reconciliation runs on: the customer who paid and closed the
     * tab is invisible to us until we ask the gateway directly.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentsForOrder(string $gatewayOrderId): array
    {
        if (! $this->isLive()) {
            // Nothing to find when the gateway is off - a simulated order was
            // never really placed, so reconciliation correctly reports it
            // abandoned rather than inventing a payment.
            return [];
        }

        $response = $this->client()->get("/orders/{$gatewayOrderId}/payments");

        if ($response->failed()) {
            Log::warning('Could not list charges for an order.', [
                'gateway_order_id' => $gatewayOrderId,
                'status' => $response->status(),
            ]);

            return [];
        }

        return collect($response->json('items', []))
            ->map(fn (array $item) => [
                'id' => $item['id'] ?? null,
                'status' => $item['status'] ?? null,
                'amount' => isset($item['amount']) ? (int) $item['amount'] : null,
                'method' => $item['method'] ?? null,
                'reference' => $item['acquirer_data']['upi_transaction_id']
                    ?? $item['acquirer_data']['bank_transaction_id']
                    ?? $item['acquirer_data']['rrn']
                    ?? null,
            ])
            ->filter(fn (array $item) => $item['id'] !== null)
            ->values()
            ->all();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(config('services.razorpay.base_url'))
            ->withBasicAuth(
                (string) config('services.razorpay.key'),
                (string) config('services.razorpay.secret')
            )
            ->acceptJson()
            // A payment request that hangs must not hold a web worker open.
            ->timeout(20)
            ->connectTimeout(5)
            ->retry(2, 300, throw: false);
    }
}
