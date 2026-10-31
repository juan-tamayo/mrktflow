@extends('default-theme::layout', [
    'pageKey' => 'account-downloads',
    'pageTitle' => 'Downloads · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'downloads-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to downloads',
    'footerLabel' => 'Downloads',
])
@section('content')
            <h1>{{ $heading }}<span class="accent">.</span></h1>
            @if(session('status'))
                <p class="theme-purchase-note">{{ session('status') }}</p>
            @endif
            @if($claimable > 0)
                <h2>Guest orders</h2>
                <p class="theme-purchase-note">{{ $claimable }} guest order(s) placed with your verified email can be linked to this account.</p>
                <form method="post" action="/account/downloads/claim">
                    @csrf
                    <button type="submit" class="text-link">Link my guest orders</button>
                </form>
            @endif
            @if(count($orders) === 0)
                <p class="theme-purchase-note">No downloadable files are available yet.</p>
            @else
                @foreach($orders as $order)
                    <h2>Order {{ $order['number'] }}</h2>
                    @if(count($order['downloads']) === 0)
                        <p class="theme-purchase-note">No downloadable files are currently available for this order.</p>
                    @else
                        <ul class="product-list">
                            @foreach($order['downloads'] as $download)
                                <li>
                                    <p>{{ $download['label'] }} · {{ $download['title'] }} · {{ $download['sku'] }} · version {{ $download['version'] }} · {{ $download['bytes'] }} bytes</p>
                                    <p class="theme-purchase-note">{{ $download['downloads_remaining'] }} of {{ $download['download_limit'] }} downloads remaining</p>
                                    @if($download['downloads_remaining'] > 0)
                                        @include('storefront.slots.download-issue', ['order' => $order, 'download' => $download, 'guest' => $guest])
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            @endif
            <p class="theme-purchase-note">Files are delivered through this area only; download links are issued per purchase and never expose file locations.</p>
@endsection
