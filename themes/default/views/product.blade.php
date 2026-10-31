@extends('default-theme::layout', [
    'pageKey' => 'product',
    'pageRobots' => $preview ? 'noindex, nofollow' : 'index, follow',
    'pageTitle' => $product->title.' · '.($preview ? 'Theme preview' : 'MRKTFlow'),
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/product.ts', 'themes/default/src/watch.ts', 'themes/default/src/compareTray.ts', 'themes/default/src/compareActions.ts', 'themes/default/src/cartIndicator.ts'],
    'mainId' => 'product-main',
    'mainClass' => 'theme-product',
    'skipLabel' => 'Skip to product',
    'footerLabel' => $preview ? 'Example storefront presentation' : 'Catalog',
    'masthead' => ['cartIndicator' => ['itemCount' => $cartItemCount, 'revision' => $cartRevision]],
])
@section('pre-main')
        @if($preview)
            <aside class="theme-preview-notice" aria-label="Preview status"><strong>Theme preview</strong><span>Example product · No purchases or live inventory</span></aside>
        @elseif($breadcrumbs !== [])
            <nav class="theme-breadcrumbs" aria-label="Breadcrumb">
                <ol>@foreach($breadcrumbs as $crumb)<li><a href="{{ $crumb['url'] }}">{{ $crumb['title'] }}</a></li>@endforeach<li aria-current="page">{{ $product->title }}</li></ol>
            </nav>
        @endif
@endsection
@section('content')
            <div class="theme-product-image"><img id="product-hero" src="{{ ($selectionState === 'selected' ? $selected['image']['url'] : $product->image['url']) }}" alt="{{ ($selectionState === 'selected' ? $selected['image']['alt'] : $product->image['alt']) }}" width="640" height="720"></div>
            <section class="theme-product-detail" aria-labelledby="product-title">
                <p class="eyebrow">{{ $preview ? 'A considered everyday' : data_get(collect($product->attributes)->firstWhere('label', 'Delivery'), 'value', 'Physical product') }}</p>
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
                        @elseif($selectionState === 'out_of_stock')
                            <p class="theme-variant-prompt" role="status">{{ $product->optionAxes === [] ? 'This product is out of stock.' : 'This combination is out of stock.' }}</p>
                        @else
                            <p class="theme-price"><span>{{ $preview ? 'Illustrative price' : 'Price' }}</span><strong>{{ $selected['money']['formatted'] }}</strong></p>
                        @endif
                        @if($preview || $product->optionAxes !== [])
                            <button type="submit" class="primary-button">Update view</button>
                        @endif
                    </form>
                </div>
                @if($preview)
                    <p class="theme-purchase-note">This preview does not create a catalog product or enable checkout.</p>
                @elseif(collect($product->variants)->contains(fn (array $variant): bool => $variant['available'] === true))
                    @include('storefront.slots.product-purchase')
                @else
                    <p class="theme-purchase-note">Checkout is not available yet.</p>
                @endif
                <section class="theme-specifications" aria-labelledby="specifications-heading"><h2 id="specifications-heading">The details</h2><dl>@foreach($product->attributes as $attribute)<dt>{{ $attribute['label'] }}</dt><dd>{{ $attribute['value'] }}</dd>@endforeach</dl></section>
            </section>
@endsection
