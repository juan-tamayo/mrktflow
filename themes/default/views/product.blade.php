<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $product->title }} · Theme preview</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/product.ts'])
</head>
<body>
    <a class="skip-link" href="#product-main">Skip to product</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/admin" aria-label="MRKTFlow workspace"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><a class="text-link" href="/admin">Back to workspace</a></header>
        <aside class="theme-preview-notice" aria-label="Preview status"><strong>Theme preview</strong><span>Example product · No purchases or live inventory</span></aside>
        <main id="product-main" class="theme-product">
            <div class="theme-product-image"><img src="{{ $product->image['url'] }}" alt="{{ $product->image['alt'] }}" width="640" height="720"></div>
            <section class="theme-product-detail" aria-labelledby="product-title">
                <p class="eyebrow">A considered everyday</p>
                <h1 id="product-title">{{ $product->title }}<span class="accent">.</span></h1>
                <p class="theme-description">{{ $product->description }}</p>
                <div id="variant-picker" data-variants="{{ json_encode(['variants' => $product->variants, 'selectedId' => $selected['id']], JSON_THROW_ON_ERROR) }}">
                    <form method="get" action="{{ $product->url }}" class="theme-variant-form">
                        <div class="setup-field"><label for="native-variant">Finish</label><select id="native-variant" name="variant">@foreach($product->variants as $variant)<option value="{{ $variant['id'] }}" @selected($variant['id'] === $selected['id'])>{{ $variant['label'] }}</option>@endforeach</select></div>
                        <p class="theme-price"><span>Illustrative price</span><strong>{{ $selected['money']['formatted'] }}</strong></p>
                        <button type="submit" class="primary-button">Update view</button>
                    </form>
                </div>
                <p class="theme-purchase-note">This preview does not create a catalog product or enable checkout.</p>
                <section class="theme-specifications" aria-labelledby="specifications-heading"><h2 id="specifications-heading">The details</h2><dl>@foreach($product->attributes as $attribute)<dt>{{ $attribute['label'] }}</dt><dd>{{ $attribute['value'] }}</dd>@endforeach</dl></section>
            </section>
        </main>
        <footer class="footer"><span>Example storefront presentation</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
