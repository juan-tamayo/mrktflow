@extends('default-theme::layout', [
    'pageKey' => 'product',
    'pageRobots' => $preview ? 'noindex, nofollow' : ($metadata?->robots ?? 'index, follow'),
    'pageDescription' => $preview ? null : ($metadata?->description ?? $product->description),
    'metadata' => $preview ? null : ($metadata ?? null),
    'pageTitle' => $product->title.' · '.($preview ? 'Example theme' : 'MRKTFlow'),
    'pageAssets' => ['resources/css/app.css', 'themes/example/src/theme.css', 'themes/example/src/product.ts'],
    'mainId' => 'example-product-main',
    'mainClass' => 'example-product',
    'skipLabel' => 'Skip to technical sheet',
    'footerLabel' => $preview ? 'Reviewed example theme' : 'Catalog',
    'masthead' => ['cartIndicator' => ['itemCount' => $cartItemCount, 'revision' => $cartRevision]],
    'assignedTemplateKey' => $assignedTemplateKey ?? 'product.default',
])
@section('pre-main')
        @if($preview)
            <aside class="theme-preview-notice" aria-label="Preview status"><strong>Example theme preview</strong><span>Trusted developer code · No purchases or live inventory</span></aside>
        @elseif($breadcrumbs !== [])
            <nav class="theme-breadcrumbs" aria-label="Breadcrumb">
                <ol>@foreach($breadcrumbs as $crumb)<li><a href="{{ $crumb['url'] }}">{{ $crumb['title'] }}</a></li>@endforeach<li aria-current="page">{{ $product->title }}</li></ol>
            </nav>
        @endif
@endsection
@section('content')
            <article class="example-spec-sheet" data-theme="example" data-visual-template="{{ $assignedTemplateKey ?? 'product.default' }}">
                <header class="example-spec-sheet__intro">
                    <p class="eyebrow">Technical sheet</p>
                    <h1 id="product-title">{{ $product->title }}</h1>
                    <p class="theme-description">{{ $product->description }}</p>
                    <p class="theme-price"><span>{{ $preview ? 'Illustrative price' : 'Price' }}</span><strong>{{ $selected['money']['formatted'] }}</strong></p>
                </header>
                <div class="example-spec-sheet__media"><img id="product-hero" src="{{ ($selectionState === 'selected' ? $selected['image']['url'] : $product->image['url']) }}" alt="{{ ($selectionState === 'selected' ? $selected['image']['alt'] : $product->image['alt']) }}" width="640" height="720"></div>
                <div id="example-spec-sheet" data-spec-sheet="{{ json_encode(['title' => $product->title, 'attributes' => $product->attributes], JSON_THROW_ON_ERROR) }}">
                    <section class="example-spec-sheet__facts" aria-labelledby="example-specifications-heading">
                        <h2 id="example-specifications-heading">Specifications</h2>
                        <dl>@foreach($product->attributes as $attribute)<dt>{{ $attribute['label'] }}</dt><dd>{{ $attribute['value'] }}</dd>@endforeach</dl>
                    </section>
                </div>
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
                    @if($preview || $product->optionAxes !== [])
                        <button type="submit" class="primary-button">Update view</button>
                    @endif
                </form>
                @if($preview)
                    <p class="theme-purchase-note">This preview does not create a catalog product or enable checkout.</p>
                @elseif(collect($product->variants)->contains(fn (array $variant): bool => $variant['available'] === true))
                    @include('storefront.slots.product-purchase')
                @else
                    <p class="theme-purchase-note">Checkout is not available yet.</p>
                @endif
            </article>
@endsection
