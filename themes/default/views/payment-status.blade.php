@extends('default-theme::layout', [
    'pageKey' => 'payment-status',
    'pageTitle' => 'Payment · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'payment-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to payment status',
    'footerLabel' => 'Checkout',
])
@section('content')
            <h1>Checkout<span class="accent">.</span></h1>
            <p class="theme-purchase-note" role="status">{{ $message }}</p>
            <p class="theme-purchase-note">Current attempt: {{ $attemptStatus }}. Order: {{ $orderStatus }}.</p>
@endsection
