@extends('default-theme::layout', [
    'pageKey' => 'home',
    'pageLang' => 'es',
    'pageRobots' => 'index, follow',
    'pageDescription' => $shopName.' — salsas, café, dulces y despensa de pequeños productores de Tijuana. Precios en pesos, envíos a todo México y Estados Unidos.',
    'pageTitle' => $shopName.' · Despensa de Tijuana',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/home.ts', 'themes/default/src/compareTray.ts', 'themes/default/src/compareActions.ts', 'themes/default/src/cartIndicator.ts'],
    'mainId' => 'home-main',
    'mainClass' => 'cn-home',
    'skipLabel' => 'Saltar al contenido',
    'footerLabel' => 'Inicio',
])
@section('content')
            <section class="cn-hero" aria-labelledby="cn-hero-title">
                <div class="cn-label-card">
                    <p class="cn-label-top"><span>Tijuana</span><span>Despensa</span><span>México</span></p>
                    <h1 id="cn-hero-title">{{ $shopName }}</h1>
                    <p class="cn-label-facts">Salsas · café · dulces · despensa<br>De pequeños productores del norte<br>Envíos a todo México y Estados Unidos</p>
                    <p class="cn-label-line">Compramos en lotes pequeños, directo de quien lo hace, y te lo mandamos a casa. Precio en pesos, sin vueltas.</p>
                    <p class="cn-label-actions">
                        <a class="cn-button" href="#despensa">Ver la despensa</a>
                        <a class="cn-text-link" href="#categorias">Ir a los pasillos</a>
                    </p>
                    <p class="cn-label-foot"><span>Hecho en Tijuana</span><span>Pago seguro con tarjeta</span></p>
                </div>
            </section>

            @if($categories !== [])
                <section id="categorias" class="cn-section" aria-labelledby="cn-categories-title">
                    <h2 id="cn-categories-title" class="cn-section-title" data-reveal>Los pasillos</h2>
                    <div class="cn-aisles">
                        @foreach($categories as $category)
                            <a class="cn-aisle" href="/categories/{{ $category['path'] }}" data-reveal>
                                <strong>{{ $category['title'] }}</strong>
                                <span class="cn-aisle-desc">{{ $category['description'] }}</span>
                                <span class="cn-aisle-meta">{{ $category['count'] }} {{ $category['count'] === 1 ? 'producto' : 'productos' }} · Ver el pasillo →</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if($featured !== null)
                <section class="cn-featured" aria-labelledby="cn-featured-title" data-reveal>
                    <div class="cn-featured-media"><img src="{{ $featured['image']['url'] }}" alt="{{ $featured['image']['alt'] }}" width="880" height="880"></div>
                    <div class="cn-featured-body">
                        <p class="cn-featured-kicker">El favorito de la casa</p>
                        <h2 id="cn-featured-title">{{ $featured['title'] }}</h2>
                        <p class="cn-featured-desc">{{ $featured['description'] }}</p>
                        <p class="cn-featured-price">{{ $featured['money']['formatted'] }}</p>
                        <p class="cn-featured-actions">
                            <a class="cn-cell cn-cell-ink" href="{{ $featured['url'] }}">Ver producto</a>
                            <a class="cn-cell" href="/search">Ver todo el catálogo</a>
                        </p>
                    </div>
                </section>
            @endif

            <section id="despensa" class="cn-section" aria-labelledby="cn-products-title">
                <h2 id="cn-products-title" class="cn-section-title" data-reveal>La despensa completa</h2>
                <p class="cn-section-sub" data-reveal>Todo lo que hay hoy en el estante.</p>
                @if($products === [])
                    <p class="theme-empty">Todavía no hay productos en el estante.</p>
                @else
                    <ul class="theme-product-grid cn-grid">
                        @foreach($products as $product)
                            <li data-reveal>
                                <a class="theme-product-card" href="{{ $product['url'] }}">
                                    <img src="{{ $product['image']['url'] }}" alt="{{ $product['image']['alt'] }}" width="480" height="540" loading="lazy">
                                    <span>{{ $product['title'] }}</span>
                                    <strong>{{ $product['money']['formatted'] }}</strong>
                                    <span class="theme-availability">{{ $product['available'] ? 'Disponible' : 'Agotado' }}</span>
                                </a>
                                <div data-compare-actions data-compare="{{ json_encode(['productId' => $product['id'], 'title' => $product['title']], JSON_THROW_ON_ERROR) }}"></div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="cn-letter" aria-label="Una nota de la casa" data-reveal>
                <p>Casa Norte empezó con una maleta. En cada viaje a Tijuana volvíamos con salsas, café y dulces para la familia del otro lado, y siempre faltaba maleta.</p>
                <p>Ahora <mark>compramos directo a los productores</mark>: la salsa macha de un taller de la Zona Centro, el café de altura que llega del sur, la cajeta que todavía se mueve con pala de madera. Lo empacamos aquí y sale a tu casa el mismo día.</p>
                <p>Si algo no te gusta, nos escribes y lo arreglamos. Así de simple.</p>
                <p class="cn-sign">La familia {{ $shopName }}<span>Tijuana, Baja California</span></p>
                <div class="cn-coupon">
                    <span class="cn-coupon-label">Tu primer pedido</span>
                    <strong class="cn-coupon-code">NORTE10</strong>
                    <span class="cn-coupon-note">10% de descuento al pagar · Sin mínimo · Escríbelo en el cupón del checkout</span>
                </div>
            </section>

            <a class="cn-cta" href="/search" data-reveal>
                <span class="cn-cta-note">Todo sale de Tijuana en 24 horas.</span>
                <span class="cn-cta-arrow" aria-hidden="true">↗</span>
                <span class="cn-cta-word">PIDE</span>
            </a>

            <p class="cn-credits">Fotografía del encabezado: «Molino de chiles en el mercado de Medellín de la Colonia Roma» por Estef93, CC BY-SA 4.0, vía Wikimedia Commons. Las fotos de los productos provienen de colecciones abiertas y están acreditadas en la documentación de esta demo.</p>
@endsection
