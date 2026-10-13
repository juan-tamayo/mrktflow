<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Search · MRKTFlow</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/search.ts'])
</head>
<body>
    <a class="skip-link" href="#search-main">Skip to search</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><span class="status">Catalog</span></header>
        <main id="search-main" class="theme-search">
            <p class="eyebrow">Search</p>
            <h1>Search<span class="accent">.</span></h1>
            <div id="catalog-filters" data-search="{{ json_encode(['query' => $search->query, 'sorts' => $search->sorts, 'categories' => $search->categories, 'facets' => $search->facets], JSON_THROW_ON_ERROR) }}">
                <form method="get" action="/search" class="theme-search-form">
                    <div class="setup-field"><label for="search-q">Find a product</label><input id="search-q" name="q" type="search" value="{{ $search->query['q'] }}" maxlength="80"></div>
                    <div class="setup-field"><label for="search-category">Category</label>
                        <select id="search-category" name="category">
                            <option value="">All categories</option>
                            @foreach($search->categories as $category)
                                <option value="{{ $category['path'] }}" @selected($search->query['category'] === $category['path'])>{{ $category['title'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="setup-field"><label for="search-price-min">Minimum price</label><input id="search-price-min" name="price_min" inputmode="numeric" value="{{ $search->query['price_min'] ?? '' }}"></div>
                    <div class="setup-field"><label for="search-price-max">Maximum price</label><input id="search-price-max" name="price_max" inputmode="numeric" value="{{ $search->query['price_max'] ?? '' }}"></div>
                    <div class="setup-field"><label for="search-available">Availability</label>
                        <select id="search-available" name="available">
                            <option value="">Any</option>
                            <option value="1" @selected($search->query['available'] === '1')>In stock</option>
                            <option value="0" @selected($search->query['available'] === '0')>Unavailable</option>
                        </select>
                    </div>
                    @foreach($search->facets as $facet)
                        @if(in_array($facet['type'], ['enum', 'boolean', 'string'], true))
                            <div class="setup-field"><label for="facet-{{ $facet['key'] }}">{{ $facet['label'] }}</label>
                                <select id="facet-{{ $facet['key'] }}" name="f[{{ $facet['key'] }}]">
                                    <option value="">Any</option>
                                    @foreach($facet['values'] as $value)
                                        <option value="{{ $value['value'] }}" @selected(($search->query['facets'][$facet['key']] ?? '') === $value['value'])>{{ $facet['type'] === 'boolean' ? ($value['value'] === '1' ? 'Yes' : 'No') : $value['value'] }} ({{ $value['count'] }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @else
                            <div class="setup-field"><label for="facet-{{ $facet['key'] }}-min">{{ $facet['label'] }} from</label><input id="facet-{{ $facet['key'] }}-min" name="f[{{ $facet['key'] }}][min]" value="{{ is_array($search->query['facets'][$facet['key']] ?? null) ? ($search->query['facets'][$facet['key']]['min'] ?? '') : '' }}"></div>
                            <div class="setup-field"><label for="facet-{{ $facet['key'] }}-max">{{ $facet['label'] }} to</label><input id="facet-{{ $facet['key'] }}-max" name="f[{{ $facet['key'] }}][max]" value="{{ is_array($search->query['facets'][$facet['key']] ?? null) ? ($search->query['facets'][$facet['key']]['max'] ?? '') : '' }}"></div>
                        @endif
                    @endforeach
                    <div class="setup-field"><label for="search-sort">Sort</label>
                        <select id="search-sort" name="sort">
                            @foreach($search->sorts as $sort)
                                <option value="{{ $sort['value'] }}" @selected($search->query['sort'] === $sort['value'])>{{ $sort['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if($search->query['page_size'] !== 24)
                        <input type="hidden" name="page_size" value="{{ $search->query['page_size'] }}">
                    @endif
                    <button type="submit" class="primary-button">Apply filters</button>
                </form>
            </div>
            @if($search->products === [])
                <p class="theme-empty">No products match this search.</p>
            @else
                <ul class="theme-product-grid">
                    @foreach($search->products as $product)
                        <li>
                            <a class="theme-product-card" href="{{ $product['url'] }}">
                                <img src="{{ $product['image']['url'] }}" alt="{{ $product['image']['alt'] }}" width="320" height="320">
                                <span>{{ $product['title'] }}</span>
                                <strong>{{ $product['money']['formatted'] }}</strong>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if($search->meta['totalPages'] > 1)
                <nav class="theme-pagination" aria-label="Search pages">
                    @if($search->meta['page'] > 1)
                        <a href="/search?{{ $search->queryString(['page' => $search->meta['page'] - 1]) }}">Previous</a>
                    @endif
                    <span>Page {{ $search->meta['page'] }} of {{ $search->meta['totalPages'] }}</span>
                    @if($search->meta['page'] < $search->meta['totalPages'])
                        <a href="/search?{{ $search->queryString(['page' => $search->meta['page'] + 1]) }}">Next</a>
                    @endif
                </nav>
            @endif
            <p class="theme-purchase-note">Checkout is not available yet. This page does not create a cart.</p>
        </main>
        <footer class="footer"><span>Catalog</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
