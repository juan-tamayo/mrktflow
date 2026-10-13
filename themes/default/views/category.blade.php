<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="index, follow">
    <title>{{ $category->title }} · MRKTFlow</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css'])
</head>
<body>
    <a class="skip-link" href="#category-main">Skip to category</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><span class="status">Catalog</span></header>
        @if($category->breadcrumbs !== [])
            <nav class="theme-breadcrumbs" aria-label="Breadcrumb">
                <ol>@foreach($category->breadcrumbs as $index => $crumb)<li>@if($index < count($category->breadcrumbs) - 1)<a href="{{ $crumb['url'] }}">{{ $crumb['title'] }}</a>@else<span aria-current="page">{{ $crumb['title'] }}</span>@endif</li>@endforeach</ol>
            </nav>
        @endif
        <main id="category-main" class="theme-category">
            <p class="eyebrow">Category</p>
            <h1>{{ $category->title }}<span class="accent">.</span></h1>
            <p class="theme-description">{{ $category->description }}</p>
            @if($category->products === [])
                <p class="theme-empty">No products in this category yet.</p>
            @else
                <ul class="theme-product-grid">
                    @foreach($category->products as $product)
                        <li>
                            <a class="theme-product-card" href="{{ $product['url'] }}">
                                <img src="{{ $product['image']['url'] }}" alt="{{ $product['image']['alt'] }}" width="320" height="320">
                                <span>{{ $product['title'] }}</span>
                                <strong>{{ $product['money']['formatted'] }}</strong>
                                @if($product['availability'] === 'out_of_stock')
                                    <span class="theme-availability">Out of stock</span>
                                @elseif($product['availability'] === 'unavailable')
                                    <span class="theme-availability">Unavailable</span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </main>
        <footer class="footer"><span>Catalog</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
