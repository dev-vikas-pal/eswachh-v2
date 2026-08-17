<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Uploading an image for a banner.
 *
 * Typing a path into a text box only works if somebody has already put the file
 * on the server by other means - which meant the banner master could reference
 * an image nobody could add.
 *
 * What is stored is a public URL, not a filesystem path, so the front end can
 * use it directly and nothing has to translate between the two.
 */
class UploadController extends Controller implements HasMiddleware
{
    /** What a banner can be. Kept small on purpose. */
    private const ALLOWED = ['jpg', 'jpeg', 'png', 'webp', 'svg'];

    public static function middleware(): array
    {
        return ['can:manage.master'];
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            /*
             * Checked as an image and by extension, not by the name the browser
             * sent. A file called banner.png that is actually a PHP script is
             * the oldest upload attack there is, and `image` reads the actual
             * contents to decide.
             */
            'file' => ['required', 'file', 'image', 'mimes:'.implode(',', self::ALLOWED), 'max:4096'],
            'folder' => ['sometimes', 'string', 'in:banners,team,posts'],
        ]);

        $folder = $request->input('folder', 'banners');

        /*
         * The stored name is ours, never theirs. An uploaded name can contain
         * path separators, unicode that renders as something else, or simply
         * collide with a file already there.
         */
        $extension = strtolower($request->file('file')->getClientOriginalExtension());
        $name = Str::uuid7().'.'.$extension;

        $path = $request->file('file')->storeAs("images/{$folder}", $name, 'public');

        return response()->json([
            'data' => [
                // A URL the browser can use, which is what the banner stores.
                'url' => Storage::disk('public')->url($path),
                'path' => $path,
                'size' => Storage::disk('public')->size($path),
            ],
        ], 201);
    }
}
