@extends('default-theme::layout', [
    'pageKey' => 'account-orders',
    'pageTitle' => 'Orders · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'orders-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to orders',
    'footerLabel' => 'Account',
])
@section('content')
            <h1>Your orders<span class="accent">.</span></h1>
            @if(count($orders) === 0)
                <p class="theme-purchase-note">You have no orders yet.</p>
            @else
                <ul class="product-list">
                    @foreach($orders as $order)
                        <li>
                            <p><a class="text-link" href="/account/orders/{{ $order['id'] }}">{{ $order['number'] }}</a> · {{ $order['email'] }} · {{ $order['status'] }} · {{ $order['grand_total']['formatted'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
@endsection
