<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="{{ $preview ? 'noindex, nofollow' : 'index, follow' }}">
    <title>{{ $product->title }} · {{ $preview ? 'Theme preview' : 'MRKTFlow' }}</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/product.ts'])
</head>
<body>
    <a class="skip-link" href="#product-main">Skip to product</a>
    <div class="page-shell theme-shell">
        @if($preview)
            <header class="masthead"><a class="wordmark" href="/admin" aria-label="MRKTFlow workspace"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><a class="text-link" href="/admin">Back to workspace</a></header>
            <aside class="theme-preview-notice" aria-label="Preview status"><strong>Theme preview</strong><span>Example product · No purchases or live inventory</span></aside>
        @else
            <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><span class="status">Catalog</span></header>
            @if($breadcrumbs !== [])
                <nav class="theme-breadcrumbs" aria-label="Breadcrumb">
                    <ol>@foreach($breadcrumbs as $crumb)<li><a href="{{ $crumb['url'] }}">{{ $crumb['title'] }}</a></li>@endforeach<li aria-current="page">{{ $product->title }}</li></ol>
                </nav>
            @endif
        @endif
        <main id="product-main" class="theme-product">
            <div class="theme-product-image"><img id="product-hero" src="{{ ($selectionState === 'selected' ? $selected['image']['url'] : $product->image['url']) }}" alt="{{ ($selectionState === 'selected' ? $selected['image']['alt'] : $product->image['alt']) }}" width="640" height="720"></div>
            <section class="theme-product-detail" aria-labelledby="product-title">
                <p class="eyebrow">{{ $preview ? 'A considered everyday' : 'Physical product' }}</p>
                <h1 id="product-title">{{ $product->title }}<span class="accent">.</span></h1>
                <p class="theme-description">{{ $product->description }}</p>
                <div id="variant-picker" data-variants="{{ json_encode($preview
                    ? ['variants' => $product->variants, 'selectedId' => $selected['id']]
                    : ['variants' => $product->variants, 'selectedId' => $selected['id'], 'optionAxes' => $product->optionAxes, 'selectionState' => $selectionState], JSON_THROW_ON_ERROR) }}">
                    <form method="get" action="{{ $product->url }}" class="theme-variant-form">
                        @if($product->optionAxes !== [])
                            <div class="setup-field"><label for="native-variant">Finish and size</label>
                                <select id="native-variant" name="variant">
                                    <option value="" @selected($selectionState === 'missing')>Choose a combination</option>
                                    @foreach($product->variants as $variant)
                                        <option value="{{ $variant['id'] }}" @selected($variant['id'] === $selected['id'] && $selectionState !== 'missing') @disabled(! $variant['available'])>{{ $variant['label'] }}{{ $variant['available'] ? '' : ' (unavailable)' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @elseif($preview)
                            <div class="setup-field"><label for="native-variant">Finish</label><select id="native-variant" name="variant">@foreach($product->variants as $variant)<option value="{{ $variant['id'] }}" @selected($variant['id'] === $selected['id'])>{{ $variant['label'] }}</option>@endforeach</select></div>
                        @endif
                        @if($selectionState === 'missing')
                            <p class="theme-variant-prompt" role="status">Choose a finish and size to see the current price.</p>
                        @elseif($selectionState === 'unavailable')
                            <p class="theme-variant-prompt" role="status">This combination is unavailable.</p>
                        @else
                            <p class="theme-price"><span>{{ $preview ? 'Illustrative price' : 'Price' }}</span><strong>{{ $selected['money']['formatted'] }}</strong></p>
                        @endif
                        @if($preview || $product->optionAxes !== [])
                            <button type="submit" class="primary-button">Update view</button>
                        @endif
                    </form>
                </div>
                <p class="theme-purchase-note">{{ $preview ? 'This preview does not create a catalog product or enable checkout.' : 'Checkout is not available yet. This page does not create a cart.' }}</p>
                <section class="theme-specifications" aria-labelledby="specifications-heading"><h2 id="specifications-heading">The details</h2><dl>@foreach($product->attributes as $attribute)<dt>{{ $attribute['label'] }}</dt><dd>{{ $attribute['value'] }}</dd>@endforeach</dl></section>
            </section>
        </main>
        <footer class="footer"><span>{{ $preview ? 'Example storefront presentation' : 'Catalog' }}</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
