{{--
    Section renderer (T063b). Receives ONLY validated sections from
    RenderSections: settings already passed their typed schemas and
    bindings arrive as resolved public summaries. This view never reads
    raw payloads, queries or models.
--}}
@foreach($sections as $section)
    @php($settings = $section['settings'])
    @php($resolved = $section['binding']['resolved'] ?? null)
    @switch($section['type'])
        @case('hero')
            <section class="section section-hero section-align-{{ $settings['alignment'] ?? 'left' }}" data-section-type="hero" data-section-id="{{ $section['id'] }}">
                <h2>{{ $settings['heading'] }}</h2>
                @isset($settings['subheading'])<p class="section-subheading">{{ $settings['subheading'] }}</p>@endisset
                @isset($settings['cta'])<p><a class="section-cta" href="{{ $settings['cta']['path'] }}">{{ $settings['cta']['label'] }}</a></p>@endisset
            </section>
            @break
        @case('rich-text')
            <section class="section section-rich-text" data-section-type="rich-text" data-section-id="{{ $section['id'] }}">
                <p>{{ $settings['text'] }}</p>
            </section>
            @break
        @case('image-text')
            <section class="section section-image-text section-image-{{ $settings['imagePosition'] ?? 'left' }}" data-section-type="image-text" data-section-id="{{ $section['id'] }}">
                <figure><img src="{{ $settings['image']['url'] }}" alt="{{ $settings['image']['alt'] }}"></figure>
                <div>
                    @isset($settings['heading'])<h2>{{ $settings['heading'] }}</h2>@endisset
                    <p>{{ $settings['text'] }}</p>
                </div>
            </section>
            @break
        @case('gallery')
            <section class="section section-gallery" data-section-type="gallery" data-section-id="{{ $section['id'] }}">
                @isset($settings['heading'])<h2>{{ $settings['heading'] }}</h2>@endisset
                <ul>
                    @foreach($settings['images'] as $image)
                        <li><figure><img src="{{ $image['url'] }}" alt="{{ $image['alt'] }}"><figcaption>{{ $image['alt'] }}</figcaption></figure></li>
                    @endforeach
                </ul>
            </section>
            @break
        @case('category-grid')
            <section class="section section-category-grid" data-section-type="category-grid" data-section-id="{{ $section['id'] }}">
                @isset($settings['heading'])<h2>{{ $settings['heading'] }}</h2>@endisset
                <ul>
                    @foreach($resolved as $category)
                        <li><a href="{{ $category['url'] }}">{{ $category['title'] }}</a></li>
                    @endforeach
                </ul>
            </section>
            @break
        @case('product-grid')
            <section class="section section-product-grid" data-section-type="product-grid" data-section-id="{{ $section['id'] }}">
                @isset($settings['heading'])<h2>{{ $settings['heading'] }}</h2>@endisset
                @isset($resolved['title'])<h3><a href="{{ $resolved['url'] }}">{{ $resolved['title'] }}</a></h3>@endisset
                <ul>
                    @foreach($section['binding']['kind'] === 'category' ? $resolved['products'] : $resolved as $product)
                        <li>
                            <a href="{{ $product['url'] }}"><img src="{{ $product['image']['url'] }}" alt="{{ $product['image']['alt'] }}">{{ $product['title'] }}</a>
                            <span class="section-price">{{ $product['money']['formatted'] }}</span>
                            @unless($product['available'])<span class="section-availability">{{ $product['availability'] }}</span>@endunless
                        </li>
                    @endforeach
                </ul>
            </section>
            @break
        @case('featured-product')
            <section class="section section-featured-product" data-section-type="featured-product" data-section-id="{{ $section['id'] }}">
                @isset($settings['heading'])<h2>{{ $settings['heading'] }}</h2>@endisset
                <article>
                    <a href="{{ $resolved['url'] }}"><img src="{{ $resolved['image']['url'] }}" alt="{{ $resolved['image']['alt'] }}">{{ $resolved['title'] }}</a>
                    <p>{{ $resolved['description'] }}</p>
                    <span class="section-price">{{ $resolved['money']['formatted'] }}</span>
                </article>
            </section>
            @break
        @case('specifications')
            <section class="section section-specifications" data-section-type="specifications" data-section-id="{{ $section['id'] }}">
                @isset($settings['heading'])<h2>{{ $settings['heading'] }}</h2>@endisset
                <h3><a href="{{ $resolved['url'] }}">{{ $resolved['title'] }}</a></h3>
                <dl>
                    @foreach($resolved['attributes'] as $attribute)
                        <div><dt>{{ $attribute['label'] }}</dt><dd>{{ $attribute['value'] }}</dd></div>
                    @endforeach
                </dl>
            </section>
            @break
        @case('comparison-link')
            <p class="section section-comparison-link" data-section-type="comparison-link" data-section-id="{{ $section['id'] }}">
                <a href="/compare?{{ http_build_query(['products' => $section['binding']['publicIds']]) }}">{{ $settings['label'] }}</a>
            </p>
            @break
        @case('faq')
            <section class="section section-faq" data-section-type="faq" data-section-id="{{ $section['id'] }}">
                @isset($settings['heading'])<h2>{{ $settings['heading'] }}</h2>@endisset
                @foreach($settings['items'] as $item)
                    <details><summary>{{ $item['question'] }}</summary><p>{{ $item['answer'] }}</p></details>
                @endforeach
            </section>
            @break
        @case('call-to-action')
            <section class="section section-call-to-action" data-section-type="call-to-action" data-section-id="{{ $section['id'] }}">
                <h2>{{ $settings['heading'] }}</h2>
                @isset($settings['text'])<p>{{ $settings['text'] }}</p>@endisset
                <p><a class="section-cta" href="{{ $settings['cta']['path'] }}">{{ $settings['cta']['label'] }}</a></p>
            </section>
            @break
        @case('article-list')
            <section class="section section-article-list" data-section-type="article-list" data-section-id="{{ $section['id'] }}">
                @isset($settings['heading'])<h2>{{ $settings['heading'] }}</h2>@endisset
                <p class="section-empty">No articles yet.</p>
            </section>
            @break
    @endswitch
@endforeach
