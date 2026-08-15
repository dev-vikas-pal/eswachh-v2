<?php

namespace App\Support\Content;

/**
 * Turns the HTML v1 stored into something safe to render.
 *
 * v1's package descriptions were pasted into a WYSIWYG editor and stored raw -
 * inline styles, font stacks, mangled attributes and all. Sending that to a
 * browser means rendering it with v-html, which hands anyone who can edit a
 * package the ability to run script in every visitor's browser.
 *
 * So it is taken apart here instead: headings and bullets come out as data, the
 * front end renders ordinary text, and no markup ever crosses the wire.
 */
class RichText
{
    /**
     * Pull the bullet points out, grouped under their headings.
     *
     * @return array<int, array{heading: ?string, items: array<int, string>}>
     */
    public static function sections(?string $html): array
    {
        if (! $html || trim($html) === '') {
            return [];
        }

        $sections = [];
        $current = ['heading' => null, 'items' => []];

        // Walk the paragraphs and list items in the order they appear, so the
        // "Exteriors" and "Interiors" grouping v1 relied on survives.
        preg_match_all('/<(p|li)\b[^>]*>(.*?)<\/\1>/is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $text = self::plain($match[2]);

            if ($text === '') {
                continue;
            }

            if (strtolower($match[1]) === 'p') {
                // A paragraph starts a new group. Flush whatever came before.
                if ($current['heading'] !== null || $current['items'] !== []) {
                    $sections[] = $current;
                }

                $current = ['heading' => rtrim($text, ':'), 'items' => []];

                continue;
            }

            $current['items'][] = $text;
        }

        if ($current['heading'] !== null || $current['items'] !== []) {
            $sections[] = $current;
        }

        return $sections;
    }

    /**
     * The whole thing as one readable line, for a summary or a title attribute.
     */
    public static function plain(?string $html): string
    {
        if (! $html) {
            return '';
        }

        // Decode first, then strip: otherwise an encoded tag survives the strip
        // and reappears as markup once the browser decodes it.
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<[^>]*>/', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * A short summary, cut on a word boundary.
     */
    public static function summary(?string $html, int $limit = 160): string
    {
        $text = self::plain($html);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace ? mb_substr($cut, 0, $lastSpace) : $cut, " ,.;:").'…';
    }
}
