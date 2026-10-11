@extends('system.account-layout')
@section('title', 'Recover your account')
@section('content')
    <p class="eyebrow">A fresh start</p>
    <h1>Recover your account<span class="accent">.</span></h1>
    <p class="lede">Request a secure code to choose a new password.</p>
    <form method="post" action="/account/forgot-password" class="account-card">
        @csrf
        @include('system.account-errors')
        @include('system.account-status')
        <div class="setup-field"><label for="recovery-email">Email</label><input id="recovery-email" name="email" type="email" required maxlength="254" autocomplete="email"></div>
        <button type="submit" class="primary-button">Send reset code</button>
    </form>
    <p class="field-help"><a class="text-link" href="/account/reset-password">I have a reset code</a> · <a class="text-link" href="/account/login">Back to sign in</a></p>
@endsection
