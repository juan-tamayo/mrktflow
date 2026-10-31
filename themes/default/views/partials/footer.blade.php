<footer class="footer cn-footer" data-platform-slot="footer">
    <p class="cn-footer-name">{{ $shopName }}</p>
    <nav class="cn-footer-nav" aria-label="Tienda">
        @foreach($shopCategories as $category)
            <a href="/categories/{{ $category['path'] }}">{{ $category['title'] }}</a>
        @endforeach
        <a href="/search">Buscar</a>
        <a href="/account">Cuenta</a>
        <a href="/cart">Carrito</a>
    </nav>
    <p class="cn-footer-legal"><span>{{ $footerLabel ?? '' }}</span><span>Tienda demo operada con MRKTFlow</span></p>
</footer>
