@extends('system.account-layout')
@section('title', 'Your profile')
@section('content')
    <p class="eyebrow">Your details</p>
    <h1>Your profile<span class="accent">.</span></h1>
    <p class="lede">Keep your name and email up to date.</p>
    <form method="post" action="/account/profile" class="account-card">
        @csrf<input type="hidden" name="profile[version]" value="{{ $profile['version'] }}">
        @include('system.account-errors')
        @include('system.account-status')
        <div class="setup-field"><label for="profile-name">Your name</label><input id="profile-name" name="profile[name]" value="{{ $profile['name'] }}" required maxlength="120" autocomplete="name"></div>
        <div class="setup-field"><label for="profile-email">Email</label><input id="profile-email" name="profile[email]" type="email" value="{{ $profile['email'] }}" required maxlength="254" autocomplete="email"></div>
        <div class="setup-field"><label for="profile-password">Current password</label><input id="profile-password" name="profile[current_password]" type="password" maxlength="72" autocomplete="current-password"><p class="field-help">Required when changing your email. You will need to confirm the new address, and your other customer sessions will be signed out.</p></div>
        <button type="submit" class="primary-button">Save profile</button>
    </form>
    <p class="field-help"><a class="text-link" href="/account">Back to your account</a></p>
@endsection
