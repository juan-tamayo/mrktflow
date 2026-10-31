@extends('system.account-layout')
@section('title', 'Download access')
@section('content')
    <p class="eyebrow">Guest orders</p>
    <h1>Confirm download access<span class="accent">.</span></h1>
    <p class="lede">Use the latest code sent to the email address on your order. Opening the email link never signs you in or uses the code — only confirming here does.</p>
    <form method="post" action="/orders/download-access/confirm" class="account-card">
        @csrf
        @include('system.account-errors')
        @include('system.account-status')
        @include('system.customer-challenge-field')
        <button type="submit" class="primary-button">Confirm download access</button>
    </form>
    <h2>Need a code?</h2>
    <p class="field-help">Enter the order ID from your receipt and the email used at checkout. If they match, we queue a new code — requesting one replaces the previous code.</p>
    <form method="post" action="/orders/download-access" class="account-card">
        @csrf
        <div class="setup-field"><label for="order-id">Order ID</label><input id="order-id" name="order_id" type="text" required autocomplete="off" spellcheck="false"></div>
        <div class="setup-field"><label for="order-email">Order email</label><input id="order-email" name="email" type="email" required autocomplete="email"></div>
        <button type="submit" class="primary-button">Email me a code</button>
    </form>
@endsection
@push('scripts')@vite('resources/js/storefront.ts')@endpush
