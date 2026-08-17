@php
    /*
     * Rendered on the server, not by Vue.
     *
     * A search engine or a chat app fetching this page reads the HTML as it
     * arrives; it does not run the JavaScript that would set these tags a
     * moment later. So the title, the description and the sharing image come
     * from the settings table here, before anything is sent.
     */
    use App\Support\Settings\SiteSettings;

    $title = SiteSettings::get('seo_title') ?: 'Eswachh — doorstep car cleaning';
    $description = SiteSettings::get('seo_description') ?: '';
    $keywords = SiteSettings::get('seo_keywords') ?: '';
    $shareImage = SiteSettings::get('seo_share_image') ?: '';
    $indexable = (bool) SiteSettings::get('seo_index');
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

    {{-- What a shared link looks like in a chat app or on social media. --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ SiteSettings::get('business_name', 'Eswachh') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:url" content="{{ url()->current() }}">
    @if ($shareImage)
        <meta property="og:image" content="{{ str_starts_with($shareImage, 'http') ? $shareImage : url($shareImage) }}">
    @endif

    <meta name="twitter:card" content="{{ $shareImage ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">

    <link rel="icon" href="/images/logo.png">

    {{-- The public bundle only. None of the admin screens are loaded here. --}}
    @vite(['resources/css/app.css', 'resources/js/site/main.ts'])
</head>
<body>
    <div id="site"></div>
</body>
</html>
