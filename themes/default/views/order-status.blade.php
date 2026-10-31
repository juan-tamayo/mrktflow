@extends('default-theme::layout', [
    'pageKey' => 'order-status',
    'pageTitle' => 'Order · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'order-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to order status',
    'footerLabel' => 'Checkout',
])
@section('content')
            <h1>Checkout<span class="accent">.</span></h1>
            @if($pending)
                <p class="theme-purchase-note" role="status">Payment is still processing. This page cannot complete the order.</p>
                <p class="theme-purchase-note">Current attempt: waiting for the processor. Order: pending.</p>
                <p><a class="text-link" href="{{ $refreshUrl }}">Check status again</a></p>
            @else
                <p class="theme-purchase-note" role="status">Order confirmed.@if($zeroTotal) No charge was taken for a zero total.@endif</p>
                <p class="theme-purchase-note">Order {{ $orderNumber }}. Total {{ $total }}. Status {{ $orderStatus }}.</p>
                <p><a class="text-link" href="/cart">Continue shopping</a></p>
            @endif
@endsection
