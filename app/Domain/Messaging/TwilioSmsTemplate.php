<?php

namespace App\Domain\Messaging;

use App\Models\MessageTemplate;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Creates one SMS template on Twilio from wording the office already wrote.
 *
 * Twilio's Content API takes a plain-text template (`twilio/text`). The body
 * may be 1,600 characters, and placeholders are positional (`{{1}}`, `{{2}}`)
 * with a sample value for each. A friendly name is 1–64 characters.
 *
 * This does not register the wording on India's DLT portal. Carriers there
 * only deliver an SMS whose text was approved on DLT, and Twilio has no API
 * for that step. Creating the template here leaves the WhatsApp name alone.
 */
final class TwilioSmsTemplate
{
    public function create(MessageTemplate $template): string
    {
        $account = (string) config('services.twilio.sid');
        $token = (string) config('services.twilio.token');

        if ($account === '' || $token === '') {
            throw new RuntimeException('Twilio has no account SID or token. Set TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN in .env.');
        }

        $provider = ProviderTemplate::for($template);
        $body = $provider->positionalBody();
        $samples = $provider->example();

        /*
         * SMS has no button, so the renewal address is the last variable.
         * The same address the SMS transport already appends when there is no
         * Content SID, so the two ways of sending say the same thing.
         */
        if ($provider->button?->isDynamic()) {
            $body = rtrim($body)."\n{{".(count($samples) + 1).'}}';
            $samples[] = rtrim((string) config('app.url'), '/').'/r/'.$provider->button->example;
        }

        if (trim($body) === '') {
            throw new RuntimeException('This template has no wording to send.');
        }

        if (mb_strlen($body) > 1600) {
            throw new RuntimeException('Twilio allows 1,600 characters in an SMS template. This wording is longer.');
        }

        $variables = [];

        foreach ($samples as $index => $sample) {
            $variables[(string) ($index + 1)] = $sample;
        }

        $payload = [
            'friendly_name' => $this->friendlyName($template),
            'language' => 'en',
            'types' => [
                'twilio/text' => ['body' => $body],
            ],
        ];

        if ($variables !== []) {
            $payload['variables'] = $variables;
        }

        try {
            $response = Http::withBasicAuth($account, $token)
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->connectTimeout(5)
                ->post('https://content.twilio.com/v1/Content', $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException('Twilio could not be reached. '.$e->getMessage());
        }

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?: $response->body());

            throw new RuntimeException('Twilio refused the template (HTTP '.$response->status().'): '.$message);
        }

        $sid = (string) $response->json('sid');

        if (! preg_match('/^HX[0-9a-fA-F]{32}$/', $sid)) {
            throw new RuntimeException('Twilio did not return a template id.');
        }

        return $sid;
    }

    /**
     * Unique each time, so replacing a template does not collide with the one
     * already stored on the account. Twilio will not rename a content template.
     */
    private function friendlyName(MessageTemplate $template): string
    {
        $name = 'eswachh_'.strtolower((string) $template->key);
        $name = preg_replace('/[^a-z0-9_]/', '_', $name) ?: 'eswachh_template';
        $name = substr($name, 0, 50).'_'.substr(dechex(time()), -6);

        return substr($name, 0, 64);
    }
}
