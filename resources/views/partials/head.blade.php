@php
    $siteName = \App\Models\Setting::siteName();
    $appName = \App\Models\Setting::appName();
    $pageTitleSuffix = \App\Models\Setting::get('general.title', $appName);
    $siteFavicon = \App\Models\Setting::faviconUrl(fallback: false);
    $faviconUrl = \App\Models\Setting::faviconUrl(fallback: true);
    $siteLogo = \App\Models\Setting::logoUrl();

    $metaTitle = \App\Models\Setting::get('seo.meta_title', filled($title ?? null) ? $title.' - '.$pageTitleSuffix : $pageTitleSuffix);
    $metaDescription = \App\Models\Setting::get('seo.meta_description', \App\Models\Setting::get('general.site_description', ''));
    $metaKeywords = \App\Models\Setting::get('seo.meta_keywords', '');
    $canonicalUrl = \App\Models\Setting::get('seo.canonical_url', request()->url());
    $ogImage = \App\Models\Setting::get('seo.og_image');
    $ogImageUrl = $ogImage ? \App\Services\FileUploadService::url((string) $ogImage) : $siteLogo;
    $twitterHandle = \App\Models\Setting::get('seo.twitter_handle', '@sntcssc');
    $gaId = \App\Models\Setting::get('seo.google_analytics_id');
@endphp

<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.$pageTitleSuffix : $pageTitleSuffix }}
</title>

@if ($metaDescription)
    <meta name="description" content="{{ $metaDescription }}" />
@endif
@if ($metaKeywords)
    <meta name="keywords" content="{{ $metaKeywords }}" />
@endif
<link rel="canonical" href="{{ $canonicalUrl }}" />

<!-- Open Graph / Social Cards -->
<meta property="og:type" content="website" />
<meta property="og:url" content="{{ $canonicalUrl }}" />
<meta property="og:title" content="{{ filled($title ?? null) ? $title.' - '.$pageTitleSuffix : $metaTitle }}" />
@if ($metaDescription)
    <meta property="og:description" content="{{ $metaDescription }}" />
@endif
@if ($ogImageUrl)
    <meta property="og:image" content="{{ $ogImageUrl }}" />
@endif

<!-- Twitter Cards -->
<meta name="twitter:card" content="summary_large_image" />
<meta name="twitter:site" content="{{ $twitterHandle }}" />
<meta name="twitter:title" content="{{ filled($title ?? null) ? $title.' - '.$pageTitleSuffix : $metaTitle }}" />
@if ($metaDescription)
    <meta name="twitter:description" content="{{ $metaDescription }}" />
@endif
@if ($ogImageUrl)
    <meta name="twitter:image" content="{{ $ogImageUrl }}" />
@endif

<link rel="icon" href="{{ $faviconUrl }}" sizes="any">
@if (!$siteFavicon)
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
@endif

@if ($gaId)
    <!-- Google Analytics -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $gaId }}');
    </script>
@endif

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])

@include('partials.theme-styles')

@php
    $appDarkMode = (string) \App\Models\Setting::get('appearance.dark_mode', 'system');
@endphp
<script>
    (function () {
        var defaultTheme = @js($appDarkMode ?: 'system');
        window.APP_DEFAULT_THEME = defaultTheme;
        var theme = defaultTheme;
        try {
            var stored = localStorage.getItem('theme');
            if (stored) {
                theme = stored;
            }
        } catch (e) {}

        var dark = theme === 'dark'
            || (theme === 'system' && (defaultTheme === 'dark' || window.matchMedia('(prefers-color-scheme: dark)').matches));

        document.documentElement.classList.toggle('dark', dark);
        document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    })();
</script>
