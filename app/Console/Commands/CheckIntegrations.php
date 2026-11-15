<?php

namespace App\Console\Commands;

use App\Domain\Billing\RazorpayGateway;
use App\Domain\Messaging\Messenger;
use App\Domain\Messaging\Transport\TransportChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Says whether the outside world is actually wired up.
 *
 * "Razorpay is not working" almost never means the code is wrong; it means a
 * key is missing, or the environment is not production, or the account is in
 * test mode and nobody said so. Those are four different problems with four
 * different fixes, and guessing between them wastes an afternoon.
 *
 * This answers the question directly, without moving any money.
 */
class CheckIntegrations extends Command
{
    protected $signature = 'eswachh:check-integrations {--ping : Also call the gateway to prove the key works}';

    protected $description = 'Report whether payments and messaging are configured, and what is stopping them';

    public function handle(RazorpayGateway $gateway, Messenger $messenger): int
    {
        $this->line('');
        $this->components->info('Environment: '.app()->environment());

        $problems = 0;

        $problems += $this->razorpay($gateway);
        $problems += $this->transports();
        $problems += $this->whatsapp($messenger);

        $this->line('');

        if ($problems === 0) {
            $this->components->info('Everything is configured.');

            return self::SUCCESS;
        }

        $this->components->warn("{$problems} thing(s) are not configured. Nothing will reach a customer until they are.");

        // Non-zero so a deployment check notices rather than scrolling past.
        return self::FAILURE;
    }

    private function razorpay(RazorpayGateway $gateway): int
    {
        $this->line('');
        $this->line('<options=bold>Razorpay</>');

        $key = (string) config('services.razorpay.key');
        $secret = (string) config('services.razorpay.secret');
        $enabled = (bool) config('services.razorpay.enabled');

        $this->row('RAZORPAY_ENABLED', $enabled ? 'true' : 'false', $enabled);
        $this->row('RAZORPAY_KEY', $key === '' ? 'not set' : $this->mask($key), $key !== '');
        // Never printed, not even masked beyond its length: a secret in a
        // terminal is a secret in a scrollback buffer.
        $this->row('RAZORPAY_SECRET', $secret === '' ? 'not set' : 'set ('.strlen($secret).' chars)', $secret !== '');

        if ($key !== '' && str_starts_with($key, 'rzp_test_')) {
            $this->components->warn('  This is a TEST key. Payments will not take real money.');
        }

        if (! $gateway->isLive()) {
            $this->components->error('  Not live. Payments take the simulated path.');

            return 1;
        }

        $this->components->info('  Live. Payments go to Razorpay.');

        if ($this->option('ping')) {
            $this->pingRazorpay();
        }

        return 0;
    }

    /**
     * Prove the key actually works, without creating anything.
     */
    private function pingRazorpay(): void
    {
        $response = Http::withBasicAuth(
            (string) config('services.razorpay.key'),
            (string) config('services.razorpay.secret'),
        )->acceptJson()->timeout(15)->get(config('services.razorpay.base_url').'/payments', ['count' => 1]);

        if ($response->successful()) {
            $this->components->info('  The key was accepted by Razorpay.');

            return;
        }

        $this->components->error(
            $response->status() === 401
                // The single most common cause, said plainly.
                ? '  Razorpay rejected the key. Check the key and secret are from the same account and the same mode.'
                : '  Razorpay answered '.$response->status().'.'
        );
    }

    /**
     * Which way out a message takes, and whether each leg is set up.
     *
     * Listed before the credentials below, because the chain decides which of
     * those credentials matter. An account perfectly configured for a provider
     * that is not in the chain is not a problem, and a chain naming a provider
     * with no credentials is one that nothing has reported until now.
     */
    private function transports(): int
    {
        $this->line('');
        $this->line('<options=bold>How messages go out</>');

        $chain = app(TransportChain::class);
        $names = $chain->configured();

        if ($names === []) {
            $this->components->error('  No transport is configured. Set MESSAGING_CHAIN.');

            return 1;
        }

        $problems = 0;

        foreach ($names as $index => $name) {
            $transport = $chain->make($name);
            $ready = $transport->isConfigured();

            $this->row(
                ($index === 0 ? 'first' : 'then').'  '.$name,
                $ready ? $transport->channel() : 'not set up',
                $ready,
            );

            if (! $ready) {
                $problems++;
            }

            if ($name === 'twilio.whatsapp' && $ready) {
                $problems += $this->twilioContent();
            }
        }

        if ($login = (array) config('messaging.login_code_chain')) {
            $this->line('  <fg=gray>sign-in code goes by: '.implode(', ', $login).'</>');
        }

        /*
         * Whether the jobs may message anybody. Not counted as a problem either
         * way - off is correct on staging and on is correct on the live site -
         * but said out loud, because "the reminders never went" and "the test
         * phone got seventy-five of them" are both this one line.
         */
        $scheduled = (bool) config('messaging.scheduled');

        $this->row('SCHEDULED_MESSAGES', $scheduled ? 'true' : 'false', $scheduled);
        $this->line($scheduled
            ? '    Reminders, the daily summary and hold notices are sent by the scheduler.'
            : '    Jobs send nothing: reminders, the daily summary and hold notices are recorded but held.');
        $this->line($scheduled
            ? '    Right for the live site. Set it to false on staging and development.'
            : '    Right for staging and development. The live site needs SCHEDULED_MESSAGES=true.');

        /*
         * An SMS leg changes what a customer receives, not just how it gets
         * there: no button, and a bill per 160 characters. Said once here so it
         * is a decision somebody made rather than something they discover.
         */
        if (in_array('twilio.sms', $names, true)) {
            $dlt = (string) config('services.twilio.dlt.entity_id');

            if ($dlt === '') {
                $this->components->warn('  SMS is in the chain but no DLT entity id is set.');
                $this->line('    India requires every sender and template to be registered with the');
                $this->line('    regulator. Twilio will accept the message and the carrier will drop it.');
                $problems++;
            }
        }

        return $problems;
    }

    /**
     * Each customer template needs its own Content SID before Twilio can send it.
     *
     * Reported only when Twilio WhatsApp is actually in the chain. An account
     * that still sends through MSG91 has nothing to fill in here.
     */
    private function twilioContent(): int
    {
        $from = (string) config('services.twilio.whatsapp_from');
        $this->row('TWILIO_WHATSAPP_FROM', $from !== '' ? $from : 'not set', $from !== '');

        $problems = 0;

        foreach ([
            'TWILIO_CONTENT_RENEWAL_DUE' => 'v2_renewal_due',
            'TWILIO_CONTENT_RENEWAL_OVERDUE' => 'v2_renewal_overdue',
            'TWILIO_CONTENT_PUT_ON_HOLD' => 'v2_put_on_hold',
        ] as $env => $template) {
            $sid = (string) config('messaging.twilio.content.'.$template);
            $this->row($env, $sid !== '' ? $sid : 'not set', $sid !== '');

            if ($sid === '') {
                $problems++;
            }
        }

        return $problems;
    }

    private function whatsapp(Messenger $messenger): int
    {
        $this->line('');
        $this->line('<options=bold>WhatsApp</>');

        $key = (string) config('services.whatsapp.key');
        $number = (string) config('services.whatsapp.number');

        $this->row('WHATSAPP_ENABLED', config('services.whatsapp.enabled') ? 'true' : 'false', (bool) config('services.whatsapp.enabled'));
        $this->row('MSG91_AUTH_KEY', $key === '' ? 'not set' : 'set', $key !== '');
        $this->row('MSG91_WHATSAPP_NUMBER', $number ?: 'not set', $number !== '');

        $problems = $this->whatsappCredentialShapes($key, $number);

        /*
         * Counted as a problem, and loudly, because it is the one setting here
         * that silently stops customers being messaged while everything else
         * reports healthy. Somebody testing on the live site sets it and the
         * risk is entirely that they forget to unset it.
         */
        if ($redirect = (string) config('services.whatsapp.test_recipient')) {
            $this->components->error('  MSG91_TEST_RECIPIENT is set to '.$redirect);
            $this->line('    Every message goes there instead of to the customer.');
            $this->line('    No customer is being messaged. Clear it when testing is done.');
            $problems++;
        }

        if (! $messenger->deliveryEnabled()) {
            $this->components->error('  Not delivering: '.$messenger->suppressionReason());
            $this->line('    Messages are still recorded, so you can see what would have gone out.');

            return $problems + 1;
        }

        /*
         * Delivering for real, from a machine whose address nobody else can
         * reach.
         *
         * This is what setting APP_ENV=production on a development machine
         * gets you, and the message it produces looks perfect: it arrives, it
         * is worded correctly, and the Renew now button under it opens
         * 127.0.0.1 - which on the phone that received it is that phone, not
         * this computer. Nothing anywhere reports a problem, because from the
         * provider's side there wasn't one.
         */
        $url = (string) config('app.url');

        if (preg_match('#^https?://(localhost|127\.|0\.0\.0\.0|\[::1\])#i', $url) === 1) {
            $this->components->warn('  Delivering for real, but APP_URL is '.$url);
            $this->line('    Messages will arrive. Any link in them - the Renew now button, a');
            $this->line('    receipt - points at this machine and is dead on the customer\'s phone.');
            $this->line('    Send from the server to test anything with a link in it.');
            $problems++;
        }

        if ($problems === 0) {
            $this->components->info('  Live. Messages reach customers.');
        }

        return $problems;
    }

    /**
     * Are these two values the right way round?
     *
     * They are easy to swap and the swap is silent from here: everything is
     * configured, the job runs, and MSG91 answers 401 for every message because
     * it was handed a phone number where the account key should be. That
     * happened on the production site, and the only visible symptom was
     * `A message could not be delivered ... "status":401` in the log.
     *
     * Checked by shape rather than by asking MSG91, deliberately. There is no
     * endpoint that validates a key without sending something, and the shapes
     * are unmistakable: an integrated number is a phone number, and an account
     * key never is.
     */
    private function whatsappCredentialShapes(string $key, string $number): int
    {
        $problems = 0;

        $looksLikeAPhoneNumber = static fn (string $v): bool => (bool) preg_match('/^\+?[0-9]{10,15}$/', $v);

        if ($key !== '' && $looksLikeAPhoneNumber($key)) {
            $this->components->error('  MSG91_AUTH_KEY is a phone number.');
            $this->line('    That is the integrated number. MSG91 answers 401 to every message');
            $this->line('    sent with it, because it is not an account key. The two are swapped.');
            $problems++;
        }

        if ($number !== '' && ! $looksLikeAPhoneNumber($number)) {
            $this->components->error('  MSG91_WHATSAPP_NUMBER is not a phone number.');
            $this->line('    It should be the WhatsApp number with its country code, digits only -');
            $this->line('    919910582309, not the account key.');
            $problems++;
        }

        return $problems;
    }

    private function row(string $name, string $value, bool $ok): void
    {
        $this->line(sprintf(
            '  %s %s  <fg=gray>%s</>',
            $ok ? '<fg=green>✓</>' : '<fg=red>✗</>',
            str_pad($name, 24),
            $value,
        ));
    }

    private function mask(string $value): string
    {
        return strlen($value) <= 12
            ? str_repeat('*', strlen($value))
            : substr($value, 0, 8).str_repeat('*', strlen($value) - 8);
    }
}
