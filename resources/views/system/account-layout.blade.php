<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <title>@yield('title') · MRKTFlow</title>
    @vite('resources/css/app.css')
</head>
<body>
    <a class="skip-link" href="#account-main">Skip to account</a>
    <div class="page-shell">
        <header class="masthead">
            <a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a>
            <a href="/" class="text-link">Storefront</a>
        </header>
        <main id="account-main" class="setup-main account-main">
            @yield('content')
        </main>
        <footer class="footer"><span>Your customer account</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
