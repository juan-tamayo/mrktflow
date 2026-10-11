@extends('system.account-layout')
@section('title', 'Choose a new password')
@section('content')
    <p class="eyebrow">Your account, secured</p>
    <h1>Choose a new password<span class="accent">.</span></h1>
    <p class="lede">Use your latest reset code. Changing your password signs out earlier customer sessions.</p>
    <form method="post" action="/account/reset-password" class="account-card">
        @csrf
        @include('system.account-errors')
        @include('system.customer-challenge-field')
        <div class="setup-field"><label for="reset-password">New password</label><input id="reset-password" name="password_reset[password]" type="password" required minlength="12" maxlength="72" autocomplete="new-password"><p class="field-help">Use at least 12 characters and at most 72 bytes.</p></div>
        <div class="setup-field"><label for="reset-confirmation">Confirm new password</label><input id="reset-confirmation" name="password_reset[password_confirmation]" type="password" required maxlength="72" autocomplete="new-password"></div>
        <button type="submit" class="primary-button">Change password</button>
    </form>
    <p class="field-help"><a class="text-link" href="/account/forgot-password">Request another code</a> · <a class="text-link" href="/account/login">Back to sign in</a></p>
@endsection
@push('scripts')@vite('resources/js/storefront.ts')@endpush
