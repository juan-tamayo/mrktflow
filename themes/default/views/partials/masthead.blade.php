<header class="masthead cn-masthead" data-platform-slot="masthead">
    <a class="wordmark cn-wordmark" href="{{ url('/') }}" aria-label="{{ $shopName }}"><span>{{ $shopName }}</span></a>
    <nav class="cn-nav" aria-label="Tienda">
        @foreach($shopCategories as $category)
            <a href="/categories/{{ $category['path'] }}">{{ $category['title'] }}</a>
        @endforeach
        <a href="/search">Buscar</a>
        <a href="/account">Cuenta</a>
    </nav>
    <div id="compare-tray"></div>
    @isset($cartIndicator)
        <span id="cart-indicator" data-cart="{{ json_encode($cartIndicator, JSON_THROW_ON_ERROR) }}"><a class="text-link" href="/cart" @if($cartCurrent ?? false) aria-current="page" @endif>@if($cartIndicator['itemCount'] > 0)Cart ({{ $cartIndicator['itemCount'] }})@else Cart @endif</a></span>
    @else
        <a class="text-link cn-cart-link" href="/cart">Carrito</a>
    @endisset
</header>
