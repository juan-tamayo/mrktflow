@extends('system.account-layout')
@section('title', 'Confirm your email')
@section('content')
    <p class="eyebrow">One last step</p>
    <h1>Confirm your email<span class="accent">.</span></h1>
    <p class="lede">Use the latest code sent to your email address.</p>
    @unless($signedIn)<p class="field-help"><a class="text-link" href="/account/login">Sign in first</a>, then reopen your email link or enter the code here.</p>@endunless
    <form method="post" action="/account/verify" class="account-card">
        @csrf
        @include('system.account-errors')
        @include('system.account-status')
        @include('system.customer-challenge-field')
        <button type="submit" class="primary-button">Confirm email</button>
    </form>
    <p class="field-help"><a class="text-link" href="/account">Back to your account</a></p>
@endsection
@push('scripts')@vite('resources/js/storefront.ts')@endpush
