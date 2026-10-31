@extends('default-theme::layout', [
    'pageKey' => 'category',
    'pageRobots' => 'index, follow',
    'pageTitle' => $category->title.' · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/compareTray.ts', 'themes/default/src/compareActions.ts'],
    'mainId' => 'category-main',
    'mainClass' => 'theme-category',
    'skipLabel' => 'Skip to category',
    'footerLabel' => 'Catalog',
])
@section('pre-main')
        @if($category->breadcrumbs !== [])
            <nav class="theme-breadcrumbs" aria-label="Breadcrumb">
                <ol>@foreach($category->breadcrumbs as $index => $crumb)<li>@if($index < count($category->breadcrumbs) - 1)<a href="{{ $crumb['url'] }}">{{ $crumb['title'] }}</a>@else<span aria-current="page">{{ $crumb['title'] }}</span>@endif</li>@endforeach</ol>
            </nav>
        @endif
@endsection
@section('content')
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
                            <div data-compare-actions data-compare="{{ json_encode(['productId' => $product['id'], 'title' => $product['title']], JSON_THROW_ON_ERROR) }}"></div>
                        </li>
                    @endforeach
                </ul>
            @endif
@endsection
