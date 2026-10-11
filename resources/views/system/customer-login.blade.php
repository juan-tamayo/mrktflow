@extends('system.account-layout')
@section('title', 'Sign in to your account')
@section('content')
    <p class="eyebrow">Welcome back</p>
    <h1>Sign in to your account<span class="accent">.</span></h1>
    <p class="lede">Your shopping account, in your hands.</p>
    <form method="post" action="/account/login" class="account-card">
        @csrf
        @include('system.account-errors')
        @include('system.account-status')
        <div class="setup-field"><label for="customer-email">Email</label><input id="customer-email" name="email" type="email" value="{{ old('email') }}" required maxlength="254" autocomplete="username"></div>
        <div class="setup-field"><label for="customer-password">Password</label><input id="customer-password" name="password" type="password" required maxlength="72" autocomplete="current-password"></div>
        <button type="submit" class="primary-button">Sign in</button>
    </form>
    <p class="field-help"><a class="text-link" href="/account/forgot-password">Forgot your password?</a></p>
    <p class="field-help">New here? <a class="text-link" href="/account/register">Create an account</a></p>
@endsection
