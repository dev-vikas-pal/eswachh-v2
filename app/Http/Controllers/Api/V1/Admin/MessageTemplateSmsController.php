<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Messaging\TwilioSmsTemplate;
use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use RuntimeException;

/**
 * Creates the Twilio SMS template for one piece of wording.
 *
 * The office edits the words on the Masters screen. This sends that wording
 * to Twilio and stores the id Twilio returns. It does not change the WhatsApp
 * name, the body, or the MSG91 export.
 */
class MessageTemplateSmsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['can:manage.master'];
    }

    public function store(string $id, TwilioSmsTemplate $twilio): JsonResponse
    {
        $template = SectorContext::withoutScope(
            fn () => MessageTemplate::query()->find($id),
        );

        if (! $template) {
            return response()->json(['message' => 'That message template does not exist.'], 404);
        }

        try {
            $sid = $twilio->create($template);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $template->forceFill(['sms_content_sid' => $sid])->save();

        return response()->json([
            'data' => [
                'id' => $template->id,
                'sms_content_sid' => $sid,
            ],
        ]);
    }
}
