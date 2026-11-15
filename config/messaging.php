<?php

return [

    /*
    |--------------------------------------------------------------------------
    | May a job send messages?
    |--------------------------------------------------------------------------
    |
    | The renewal reminders, the daily summary and the "put on hold" notice are
    | sent by scheduled jobs, with nobody watching. On a staging or development
    | copy that is real customer data being messaged about - or, with the test
    | number set, one phone receiving every customer's reminder in the same
    | minute, which WhatsApp treats as spam and then blocks.
    |
    | Off unless a server turns it on. The live site sets
    |
    |   SCHEDULED_MESSAGES=true
    |
    | and nothing else should. It holds only what a job sends: a message the
    | office sends from a screen, a receipt after a payment and a sign-in code
    | are web requests and still go. The jobs still run - an overdue plan is
    | still paused - and each held message is recorded with the reason.
    |
    */
    'scheduled' => (bool) env('SCHEDULED_MESSAGES', false),

    /*
    |--------------------------------------------------------------------------
    | Which way a message goes out, and in what order
    |--------------------------------------------------------------------------
    |
    | Tried left to right until one of them takes the message. The useful
    | arrangement is a channel that can carry a button followed by one that
    | always arrives:
    |
    |   MESSAGING_CHAIN=twilio.whatsapp,twilio.sms
    |
    | Known transports: msg91.whatsapp, twilio.whatsapp, twilio.sms.
    |
    | Defaults to MSG91 alone, which is what every message has gone out by so
    | far. A deployment that says nothing keeps working exactly as it did.
    |
    | Falling back is not free and not invisible: an SMS has no button, so the
    | renewal link is appended to the text instead, and it is billed per
    | 160 characters. Both are recorded on the message, so "why did this one
    | look different" has an answer.
    |
    */

    'chain' => array_filter(array_map('trim', explode(
        ',',
        (string) env('MESSAGING_CHAIN', 'msg91.whatsapp'),
    ))),

    /*
    |--------------------------------------------------------------------------
    | The sign-in code
    |--------------------------------------------------------------------------
    |
    | Its own chain, because it is not like the others. Everything else tells a
    | customer something; this one is the only way into their account, and a
    | message that does not arrive locks them out rather than leaving them
    | uninformed.
    |
    | Currently WhatsApp, which is where it has always gone. SMS is the more
    | reliable channel for a code - it needs no app, no data and no smartphone -
    | and moving it is one line here once DLT registration is in place.
    |
    | Empty means "use the chain above".
    |
    */

    'login_code_chain' => array_filter(array_map('trim', explode(
        ',',
        (string) env('MESSAGING_LOGIN_CODE_CHAIN', ''),
    ))),

    'twilio' => [

        /*
        |----------------------------------------------------------------------
        | Template name => Twilio Content SID
        |----------------------------------------------------------------------
        |
        | Twilio does not address a template by the name Meta approved. It
        | issues its own identifier (HX...) when the template is created through
        | its Content API, and that is what a send must quote.
        |
        | Configuration rather than a database column, for the same reason the
        | MSG91 name is a database column rather than code: each is a fact about
        | one account. The name belongs to the wording and moves with it between
        | environments; a Content SID belongs to one Twilio project and does not.
        |
        | A template with no SID here cannot be sent by Twilio WhatsApp, and the
        | transport says so by name rather than failing vaguely.
        |
        */

        'content' => array_filter([
            'v2_renewal_due' => env('TWILIO_CONTENT_RENEWAL_DUE'),
            'v2_renewal_overdue' => env('TWILIO_CONTENT_RENEWAL_OVERDUE'),
            'v2_put_on_hold' => env('TWILIO_CONTENT_PUT_ON_HOLD'),
        ]),
    ],

];
