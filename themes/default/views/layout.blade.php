{{--
    The one storefront layout (T062). Every themed page extends this file
    and fills the theme slots (head, announcement, pre-main, content); the
    masthead and footer are platform-positioned chrome the layout includes
    at the contract positions, and the composition in $composedPage (built
    by the layout view composer through ComposePage) names the page and its
    slots. Page templates pass their head facts as extends data: pageKey,
    pageTitle, pageAssets, mainId, mainClass, footerLabel, and optionally
    pageLang, pageRobots (default noindex), pageDescription, skipLabel and
    masthead (include data for the chrome partial).
--}}
<!DOCTYPE html>
<html lang="{{ $pageLang ?? 'en' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ $pageRobots ?? 'noindex, nofollow' }}">
    @isset($pageDescription)<meta name="description" content="{{ $pageDescription }}">@endisset
    <title>{{ $pageTitle }}</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite($pageAssets ?? ['resources/css/app.css', 'themes/default/src/theme.css'])
    @yield('head')
</head>
<body>
    <a class="skip-link" href="#{{ $mainId ?? 'main' }}">{{ $skipLabel ?? 'Skip to content' }}</a>
    <div class="page-shell theme-shell" data-layout="storefront" @isset($composedPage) data-page="{{ $composedPage->page }}" @endisset>
        @yield('announcement')
        @if($preview ?? false)
            <header class="masthead" data-platform-slot="masthead"><a class="wordmark" href="/admin" aria-label="MRKTFlow workspace"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><a class="text-link" href="/admin">Back to workspace</a></header>
        @else
            @include($composedPage?->viewFor('masthead') ?? 'default-theme::partials.masthead', $masthead ?? [])
        @endif
        @yield('pre-main')
        <main id="{{ $mainId ?? 'main' }}" @isset($mainClass) class="{{ $mainClass }}" @endisset>
            @yield('content')
        </main>
        @include($composedPage?->viewFor('footer') ?? 'default-theme::partials.footer', ['footerLabel' => $footerLabel ?? ''])
    </div>
</body>
</html>
