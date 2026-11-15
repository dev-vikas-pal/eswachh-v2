<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The four renderings that shipped with the system.
     *
     * Near-black, made to sit full-bleed under white text. The public site is
     * light now and shows a banner's picture in a frame beside the headline,
     * where these read as a black slab.
     */
    private const SHIPPED = [
        '/images/banners/beaded-dusk.jpg',
        '/images/banners/beaded-dawn.jpg',
        '/images/banners/beaded-monsoon.jpg',
        '/images/banners/beaded-night.jpg',
    ];

    /**
     * Banners still using one of them lose the picture, and the hero draws its
     * own light panel in its place.
     *
     * Matched on the exact path, so a banner the office has given a picture of
     * its own is never touched - only the ones nobody has changed since the
     * seeder wrote them.
     */
    public function up(): void
    {
        DB::table('banners')
            ->whereIn('image_path', self::SHIPPED)
            ->update(['image_path' => null]);
    }

    /**
     * Not reversed: which banner had which rendering is not recorded once it
     * has gone, and the files are still in public/images/banners to be chosen
     * again from the Banners master.
     */
    public function down(): void
    {
        //
    }
};
