<?php

namespace App\Support\Content;

/**
 * Reduces pasted HTML to a short whitelist, on the way in.
 *
 * v1 stored whatever its editor produced - inline styles, font stacks, mangled
 * attributes, and anything else the clipboard carried - and rendered it raw.
 * Two problems: the admin screens end up showing markup instead of text, and
 * anyone who can edit a package can run script on the public site.
 *
 * Cleaning on write rather than on read is deliberate. Sanitising at render
 * time means the mess is still in the database, so every future reader has to
 * remember to clean it, and one that forgets is a hole. Cleaned on the way in,
 * the stored value is already safe and every reader is simple.
 */
class HtmlSanitizer
{
    /**
     * Tags worth keeping in a description. Anything structural or interactive -
     * script, style, iframe, form, a - is not on the list, and no attribute
     * survives at all, which removes the whole onclick/style/href class of
     * problem rather than trying to filter it.
     */
    private const ALLOWED = ['p', 'br', 'ul', 'ol', 'li', 'strong', 'em', 'b', 'i', 'h3', 'h4'];

    /** Normalised to the tag we keep. */
    private const ALIASES = ['b' => 'strong', 'i' => 'em', 'h1' => 'h3', 'h2' => 'h3', 'div' => 'p'];

    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        // Remove these outright, contents and all: stripping only the tag would
        // leave the script body sitting in the page as text, which some
        // contexts will happily execute.
        $html = preg_replace('/<(script|style|iframe|object|embed|form)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
        $html = preg_replace('/<(script|style|iframe|object|embed|form)\b[^>]*\/?>/i', '', $html) ?? '';

        // Comments can hide markup from a naive parser.
        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';

        $allowed = self::ALLOWED;

        $html = preg_replace_callback(
            '/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)\b[^>]*>/',
            function (array $match) use ($allowed): string {
                $closing = $match[1] === '/';
                $tag = strtolower($match[2]);
                $tag = self::ALIASES[$tag] ?? $tag;

                if (! in_array($tag, $allowed, true)) {
                    return '';
                }

                // Rebuilt from the tag name alone. Nothing an attribute could
                // carry - style, class, on*, href - survives this.
                return $closing ? "</{$tag}>" : "<{$tag}>";
            },
            $html,
        ) ?? '';

        // Empty wrappers left behind by the stripping above.
        $html = preg_replace('/<(p|li|h3|h4)>\s*<\/\1>/', '', $html) ?? '';
        $html = preg_replace('/\s+/u', ' ', $html) ?? '';
        $html = str_replace(['> <', '</p> <p>'], ['><', '</p><p>'], $html);

        $html = trim($html);

        return $html === '' ? null : $html;
    }

    /**
     * Was anything actually removed?
     *
     * Used to tell an administrator that their paste was tidied, rather than
     * silently changing what they typed and letting them find out later.
     */
    public static function wasChanged(?string $original, ?string $cleaned): bool
    {
        return trim((string) $original) !== trim((string) $cleaned);
    }
}
