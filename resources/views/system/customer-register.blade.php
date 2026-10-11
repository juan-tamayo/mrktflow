@extends('system.account-layout')
@section('title', 'Create your account')
@section('content')
    <p class="eyebrow">A place of your own</p>
    <h1>Create your account<span class="accent">.</span></h1>
    <p class="lede">Keep your shopping details together in one place.</p>
    <form method="post" action="/account/register" class="account-card">
        @csrf
        @include('system.account-errors')
        <div class="setup-field"><label for="customer-name">Your name</label><input id="customer-name" name="customer[name]" required maxlength="120" autocomplete="name"></div>
        <div class="setup-field"><label for="customer-email">Email</label><input id="customer-email" name="customer[email]" type="email" required maxlength="254" autocomplete="username"></div>
        <div class="setup-field"><label for="customer-password">Password</label><input id="customer-password" name="customer[password]" type="password" required minlength="12" maxlength="72" autocomplete="new-password" aria-describedby="password-help"></div>
        <div class="setup-field"><label for="customer-confirmation">Confirm password</label><input id="customer-confirmation" name="customer[password_confirmation]" type="password" required maxlength="72" autocomplete="new-password"></div>
        <p id="password-help" class="field-help">Use a unique password with at least 12 characters and at most 72 bytes.</p>
        <button type="submit" class="primary-button">Create account</button>
    </form>
    <p class="field-help">Already have an account? <a class="text-link" href="/account/login">Sign in</a></p>
@endsection
