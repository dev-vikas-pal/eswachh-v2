@php
    /*
     * Rendered on the server, not by Vue.
     *
     * A search engine or a chat app fetching this page reads the HTML as it
     * arrives; it does not run the JavaScript that would set these tags a
     * moment later. So the title, the description, the sharing image and the
     * structured data below all come from the database here, before anything is
     * sent.
     */
    use App\Models\Faq;
    use App\Support\Settings\SiteSettings;

    $business = SiteSettings::get('business_name') ?: 'Eswachh';
    $baseTitle = SiteSettings::get('seo_title') ?: $business.' — doorstep car cleaning';
    $description = SiteSettings::get('seo_description') ?: '';
    $keywords = SiteSettings::get('seo_keywords') ?: '';
    $shareImage = SiteSettings::get('seo_share_image') ?: '';
    $indexable = (bool) SiteSettings::get('seo_index');

    $phone = SiteSettings::get('contact_phone') ?: '';
    $email = SiteSettings::get('contact_email') ?: '';
    $address = SiteSettings::get('address') ?: '';
    $hours = SiteSettings::get('office_hours') ?: '';

    /*
     * A title per page, worked out from the path.
     *
     * Every route shared the one title, so eight pages competed with each other
     * in search results under the same name and a bookmarked price list was
     * indistinguishable from the home page. The router owns the paths; this
     * only has to name them.
     */
    $path = trim(parse_url(request()->getRequestUri(), PHP_URL_PATH) ?? '', '/');
    $section = explode('/', $path)[0] ?? '';

    $sectionTitle = match ($section) {
        'packages' => 'Plans and prices',
        'subscribe' => 'Start a subscription',
        'renew' => 'Renew your plan',
        'questions' => 'Common questions',
        'blog' => 'Car care advice',
        'team' => 'Our team',
        'contact' => 'Contact us',
        'cloths' => 'Cloth ironing top-up',
        'policy' => 'Policies',
        default => '',
    };

    $title = $sectionTitle ? $sectionTitle.' · '.$business : $baseTitle;

    /*
     * The questions the office already answers, offered to search engines as
     * structured data.
     *
     * These are the same FAQs the site shows, read from the same table - so a
     * question answered in the office on Monday can appear as a rich result
     * without anybody editing markup. Capped and stripped of tags, because this
     * is a data feed rather than a page: a rich result will not render markup,
     * and sending thirty of them helps nobody.
     */
    $faqs = $indexable
        ? Faq::query()->live()->limit(10)->get(['question', 'answer'])
        : collect();

    $absolute = fn (string $path) => str_starts_with($path, 'http') ? $path : url($path);

    /*
     * Structured data, built here rather than inline in the markup.
     *
     * Two things a search engine can use directly: what this business is and
     * where to reach it, and the questions it already answers. Both read from
     * the database, so neither can drift away from what the site says.
     *
     * Nulls are stripped: an empty telephone or address in structured data is
     * worse than none at all, because it is a claim that there is nothing to
     * say rather than a field nobody has filled in yet.
     */
    $schema = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'LocalBusiness',
        'name' => $business,
        'description' => $description ?: null,
        'url' => url('/'),
        'image' => $shareImage ? $absolute($shareImage) : $absolute('/images/logo.png'),
        'logo' => $absolute('/images/logo.png'),
        'telephone' => $phone ?: null,
        'email' => $email ?: null,
        'address' => $address
            ? ['@type' => 'PostalAddress', 'streetAddress' => $address, 'addressCountry' => 'IN']
            : null,
        'openingHours' => $hours ?: null,
        'priceRange' => '₹₹',
        'makesOffer' => [
            '@type' => 'Offer',
            'itemOffered' => [
                '@type' => 'Service',
                'name' => 'Doorstep car cleaning subscription',
                'serviceType' => 'Car cleaning',
            ],
        ],
    ], fn ($value) => $value !== null);

    $faqSchema = $faqs->isEmpty() ? null : [
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $faqs->map(fn ($faq) => [
            '@type' => 'Question',
            'name' => $faq->question,
            'acceptedAnswer' => [
                '@type' => 'Answer',
                // Tags stripped: a rich result renders text, not markup.
                'text' => trim(html_entity_decode(strip_tags((string) $faq->answer))),
            ],
        ])->values()->all(),
    ];

    $asJson = fn (array $data) => json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP,
    );
@endphp
<!DOCTYPE html>
<html lang="en" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $description }}">
    @if ($keywords)
        <meta name="keywords" content="{{ $keywords }}">
    @endif

    {{--
        A staging copy that gets indexed competes with the real site for its
        own name, so this is switchable rather than assumed.
    --}}
    @unless ($indexable)
        <meta name="robots" content="noindex, nofollow">
    @endunless

    <link rel="canonical" href="{{ url()->current() }}">

    {{-- The browser chrome picks this up, so a phone frames the page in the
         brand colour instead of white. --}}
    <meta name="theme-color" content="#EA580C">

    {{-- What a shared link looks like in a chat app or on social media. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $business }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:locale" content="en_IN">
    @if ($shareImage)
        <meta property="og:image" content="{{ $absolute($shareImage) }}">
        <meta property="og:image:alt" content="{{ $business }}">
    @endif

    <meta name="twitter:card" content="{{ $shareImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    @if ($shareImage)
        <meta name="twitter:image" content="{{ $absolute($shareImage) }}">
    @endif

    <link rel="icon" href="/images/logo.png">

    {{-- What this business is, and where to reach it. --}}
    <script type="application/ld+json">{!! $asJson($schema) !!}</script>

    {{--
        And the questions it already answers.

        The same FAQs the site shows, from the same table - so a question
        answered in the office on Monday can appear as a rich result without
        anybody editing markup.
    --}}
    @if ($faqSchema)
        <script type="application/ld+json">{!! $asJson($faqSchema) !!}</script>
    @endif

    {{-- The public bundle only. None of the admin screens are loaded here. --}}
    @vite(['resources/css/app.css', 'resources/js/site/main.ts'])
</head>
<body>
    {{--
        The headline in the HTML, before any JavaScript runs.

        A crawler that does not execute scripts - and a person on a slow
        connection - otherwise sees an empty page. Vue replaces this the moment
        it mounts, so it is never shown twice.
    --}}
    <div id="site">
        <noscript>
            <h1>{{ $title }}</h1>
            <p>{{ $description }}</p>
            @if ($phone)
                <p>Call {{ $phone }}@if ($hours), {{ $hours }}@endif.</p>
            @endif
        </noscript>
    </div>
</body>
</html>
