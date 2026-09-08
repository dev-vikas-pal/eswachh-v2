<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Messaging\Msg91Export;
use App\Http\Controllers\Controller;
use App\Models\MessageTemplate;
use App\Support\Tenancy\SectorContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * The message wording, written out as files MSG91 will import.
 *
 * MSG91's Templates screen has an Import button that takes one JSON file at a
 * time and accepts nothing but a WhatsApp exported template. So this produces
 * exactly that: one file per template, named for the template it creates, and a
 * zip of the lot for the first upload when there are seventeen of them.
 *
 * Why an export at all, rather than typing them into MSG91 by hand
 * ----------------------------------------------------------------
 * Because the wording lives here and is edited here. Retyping it into the
 * provider is where the two drift apart, and the drift is invisible from our
 * side: our own `messages` row renders from our copy and reads perfectly, while
 * the customer receives whatever was approved months ago. Generating the file
 * from the same object the sender uses means the approved template and the
 * message we send cannot describe different variables.
 *
 * Behind manage.master, with the Messages screen it belongs to. A template file
 * carries no customer data - it is wording and placeholders - but it is the
 * business's own copy and belongs with the rest of the administration.
 */
class MessageTemplateExportController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['can:manage.master'];
    }

    /**
     * Is the address in these files one a customer's phone can actually open?
     *
     * The Renew now button is built from APP_URL, and a template is approved
     * with the address baked into it - so exporting from a developer's machine
     * produces files whose button points at 127.0.0.1, and nothing about them
     * looks wrong. Meta approves it, the message is delivered, and the button
     * fails only on the customer's phone, where nobody here will ever see it.
     *
     * Reported rather than refused: exporting locally to read the file is a
     * perfectly reasonable thing to do, and the only mistake is uploading it.
     */
    private function addressWarning(): ?string
    {
        $url = (string) config('app.url');

        $looksLocal = preg_match('#^https?://(localhost|127\.|0\.0\.0\.0|\[::1\])#i', $url) === 1
            || str_ends_with(parse_url($url, PHP_URL_HOST) ?: '', '.test');

        if ($looksLocal) {
            return "The Renew now button in these files points at {$url}, which is this machine. "
                .'Do not upload them to MSG91 - a template is approved with its address built in, '
                .'and the button would fail on every customer phone. Export again from the server.';
        }

        /*
         * Meta will not approve a URL button on plain http.
         *
         * Worth its own check because APP_URL is where the scheme comes from,
         * and a server serving https perfectly well can still have http written
         * there - nothing else in the application would ever notice.
         */
        if (! str_starts_with(strtolower($url), 'https://')) {
            return "APP_URL is {$url}, so the Renew now button is not https. Meta refuses a URL "
                .'button that is not, and the rejection names the template rather than the setting. '
                .'Fix APP_URL on the server and export again.';
        }

        return null;
    }

    /**
     * Every template, zipped.
     *
     * Unscoped like the rest of the masters: message wording is a shared
     * catalogue rather than any sector's property.
     */
    public function all(): BinaryFileResponse|JsonResponse
    {
        $templates = SectorContext::withoutScope(
            fn () => MessageTemplate::query()
                ->whereNotNull('provider_template')
                ->orderBy('key')
                ->get()
        );

        if ($templates->isEmpty()) {
            return response()->json([
                'message' => 'There is no message wording to export yet. Seed the message templates first.',
            ], 422);
        }

        $path = tempnam(sys_get_temp_dir(), 'eswachh-templates-');

        if ($path === false) {
            throw new RuntimeException('Could not open a temporary file to write the export into.');
        }

        $zip = new ZipArchive;

        // OVERWRITE, not CREATE: tempnam has already made the file, and
        // ZipArchive refuses to open an existing empty one as a new archive.
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not write the template export.');
        }

        foreach ($templates as $template) {
            $export = new Msg91Export($template);
            $zip->addFromString($export->filename(), $export->json());
        }

        $zip->close();

        return response()
            ->download(
                $path,
                'eswachh-templates-'.Carbon::today()->toDateString().'.zip',
                // A header rather than something in the file: the file is
                // uploaded to MSG91 untouched, so nothing may be added to it.
                array_filter(['X-Export-Warning' => $this->addressWarning()]),
            )
            // The file is in the system temp directory and nothing else will
            // ever clean it up.
            ->deleteFileAfterSend();
    }

    /**
     * One template, as the file to upload for it.
     *
     * The one that gets used day to day: templates are approved one at a time,
     * and after the first upload only the one whose wording changed needs
     * submitting again.
     */
    public function one(string $id): JsonResponse
    {
        $template = SectorContext::withoutScope(
            fn () => MessageTemplate::query()->find($id)
        );

        abort_unless($template !== null, 404);

        if (! $template->provider_template) {
            return response()->json([
                'message' => 'This template has no provider name, so there is nothing to upload it as.',
            ], 422);
        }

        $export = new Msg91Export($template);

        /*
         * Sent as the export itself rather than wrapped in a `data` key, and
         * with a download filename on it, because the file that arrives is
         * uploaded to MSG91 untouched. A wrapper would have to be stripped by
         * hand first, and the note on their Import dialog is explicit that it
         * accepts nothing but an exported template.
         */
        return response()
            ->json($export->toArray(), 200, array_filter([
                'Content-Disposition' => 'attachment; filename="'.$export->filename().'"',
                'X-Export-Warning' => $this->addressWarning(),
            ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
