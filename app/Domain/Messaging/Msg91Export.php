<?php

namespace App\Domain\Messaging;

use App\Models\MessageTemplate;

/**
 * One of our templates, written out in the file MSG91 will import.
 *
 * MSG91's Templates screen takes a single JSON file at a time and says plainly
 * that it accepts nothing but a WhatsApp exported template, so this reproduces
 * that envelope rather than inventing a shape of our own. The files already in
 * docs/msg91template are exports of the live v1 templates and are what this was
 * written against - if the importer ever refuses one of ours, diffing it
 * against one of those is the fastest way to see why.
 *
 * The wording is not restated here. Everything about the body - its text, its
 * variables and their order - comes from ProviderTemplate, which is the same
 * object Messenger sends with. That is the point: a template approved from this
 * file and a message sent by that code cannot describe different variables,
 * because there is only one description.
 *
 * What is deliberately not carried over
 * -------------------------------------
 * An export from MSG91 carries three things the account assigned to it: `id`,
 * `msg91_template_id`, and an approval `status`. A file being uploaded to
 * create a template has none of them yet. They are written as nulls rather than
 * dropped, so the envelope still has the shape the importer is reading for, and
 * so nothing here ever claims a template was approved before it was submitted.
 */
final class Msg91Export
{
    public function __construct(
        private readonly MessageTemplate $template,
    ) {}

    /**
     * The file name this should be uploaded as.
     *
     * The provider name, not our key: it is what the template will be called at
     * MSG91, so a folder of these reads as the template list it produces.
     */
    public function filename(): string
    {
        return $this->name().'.json';
    }

    public function json(): string
    {
        // Pretty printed and with real slashes, because these files are read by
        // people - compared against the samples, pasted into a support ticket -
        // at least as often as they are read by the importer.
        return (string) json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $provider = ProviderTemplate::for($this->template);
        $name = $this->name();

        return [
            /*
             * Utility, for all of them.
             *
             * Everything this business sends is about a plan somebody already
             * holds - it expired, it was paused, the car was cleaned, money was
             * received. Marketing is a different category with different consent
             * rules, and nothing here is marketing.
             */
            'category' => 'UTILITY',
            'name' => $name,
            'namespace' => (string) config('services.whatsapp.namespace'),
            'languages' => [
                [
                    // Assigned by MSG91 when the template is created.
                    'id' => null,
                    'name' => $name,
                    'language' => 'en',
                    'parameter_format' => 'POSITIONAL',
                    'status' => 'pending',
                    'rejection_reason' => 'NONE',
                    'default_variables' => null,
                    'code' => $this->code($provider),
                    'gtwy_thread_id' => null,
                    'msg91_template_id' => null,
                    'quality_score' => 'UNKNOWN',
                    'backup_templates' => [],
                    'variables' => $provider->variableNames(),
                    'variable_type' => $this->variableTypes($provider),
                ],
            ],
            'is_disabled' => 0,
            'ttl_in_seconds' => null,
            'gtwy_thread_id' => null,
        ];
    }

    /**
     * The components of the message: the body, and a button where there is one.
     *
     * No FOOTER, deliberately, even though some of the v1 exports have one. Our
     * bodies already end with their own sign-off, and lifting the last line out
     * into a footer would make the text we store on the `messages` row differ
     * from the text the customer receives - a difference that would only ever
     * be noticed by somebody comparing a complaint against our own record.
     *
     * @return array<int, array<string, mixed>>
     */
    private function code(ProviderTemplate $provider): array
    {
        $body = [
            'text' => $provider->positionalBody(),
            'type' => 'BODY',
        ];

        /*
         * A template with no variables carries no example, matching the v1
         * exports - `toall` has neither. An empty example on a body with
         * nothing to fill in is a malformed submission rather than an empty
         * one.
         */
        if ($provider->hasVariables()) {
            $body['example'] = ['body_text' => [$provider->example()]];
        }

        $code = [$body];

        if ($button = $provider->button) {
            $code[] = [
                'type' => 'BUTTONS',
                'buttons' => [
                    [
                        'type' => 'URL',
                        'text' => $button->text,
                        'url' => $button->urlPattern(),
                        'example' => [$button->exampleUrl()],
                    ],
                ],
            ];
        }

        return $code;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function variableTypes(ProviderTemplate $provider): array
    {
        $types = [];

        foreach ($provider->variableNames() as $variable) {
            $types[$variable] = ['type' => 'text'];
        }

        return $types;
    }

    private function name(): string
    {
        return (string) $this->template->provider_template;
    }
}
