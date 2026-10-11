<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    <title>This store is getting ready · MRKTFlow</title>
    @vite(['resources/css/app.css', 'resources/js/storefront.ts'])
</head>
<body>
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="page-shell">
        <header class="masthead">
            <a class="wordmark" href="/">mrktflow<span aria-hidden="true">•</span></a>
            <span class="status"><span class="status-dot" aria-hidden="true"></span>Opening soon</span>
        </header>
        <main id="main" class="intro">
            <p class="eyebrow">A new beginning</p>
            <h1>This store <br>is getting ready<span class="accent">.</span></h1>
            <p class="lede">Good things take a little preparation. We're putting the finishing touches on a new place to shop.</p>
            <div id="store-notice" data-question="Can I place an order?" data-answer="Not yet. This store hasn't opened. Please check back soon.">
                <details class="store-notice"><summary>Can I place an order?</summary><p>Not yet. This store hasn't opened. Please check back soon.</p></details>
            </div>
        </main>
        <footer class="footer"><span>Independent by design.</span><a href="/admin">Store administration <span aria-hidden="true">↗</span></a></footer>
    </div>
</body>
</html>
