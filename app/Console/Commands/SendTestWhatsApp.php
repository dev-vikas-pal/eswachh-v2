<?php

namespace App\Console\Commands;

use App\Domain\Messaging\Messenger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One WhatsApp message through Twilio, for proving the sender works.
 *
 * A Content template, not free text. This account rejects a Body-only
 * WhatsApp message. The sender is TWILIO_WHATSAPP_FROM. The template is
 * --content, or TWILIO_CONTENT_TEST when that option is left off.
 *
 * It does not go through MSG91, the production gate, or the test-recipient
 * redirect, so a run here cannot be mistaken for a customer message.
 */
class SendTestWhatsApp extends Command
{
    protected $signature = 'eswachh:send-whatsapp
        {to : The phone number, with or without a country code}
        {--content= : Twilio Content SID (HX...). Required: this account rejects plain text}
        {--var=* : Template values, in order, as --var=Rahul --var=UP16}';

    protected $description = 'Send one WhatsApp message through Twilio';

    public function handle(): int
    {
        $sid = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');
        $from = ltrim((string) config('services.twilio.whatsapp_from'), '+');

        if ($sid === '' || $token === '' || $from === '') {
            $this->components->error('Twilio has no account SID, token, or WhatsApp sender.');

            return self::FAILURE;
        }

        $content = (string) ($this->option('content') ?: config('services.twilio.test_content'));

        if ($content === '') {
            $this->components->error('No Content SID. Pass --content=HXxxxx, or set TWILIO_CONTENT_TEST in .env.');

            return self::FAILURE;
        }

        $to = Messenger::dialable((string) $this->argument('to'));
        $variables = [];

        foreach (array_values((array) $this->option('var')) as $index => $value) {
            $variables[(string) ($index + 1)] = (string) $value;
        }

        $payload = [
            'To' => 'whatsapp:+'.$to,
            'From' => 'whatsapp:+'.$from,
            'ContentSid' => $content,
        ];

        if ($variables !== []) {
            $payload['ContentVariables'] = json_encode($variables);
        }

        $this->line('From whatsapp:+'.$from);
        $this->line('To   whatsapp:+'.$to);
        $this->line('Template '.$content);

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->acceptJson()
                ->timeout(15)
                ->connectTimeout(5)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", $payload);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->components->error('HTTP '.$response->status().': '.$response->body());

            return self::FAILURE;
        }

        $this->components->info('Twilio accepted it. SID: '.$response->json('sid'));
        $this->line('Status: '.($response->json('status') ?: 'queued'));

        return self::SUCCESS;
    }
}
