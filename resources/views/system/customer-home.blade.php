@extends('system.account-layout')
@section('title', 'Your account')
@section('content')
    <p class="eyebrow">Your customer account</p>
    <h1>Make yourself at home<span class="accent">.</span></h1>
    <p class="lede">Welcome, {{ $customer['name'] }}.</p>
    <section class="account-card" aria-labelledby="details-heading">
        <h2 id="details-heading">Account details</h2>
        <dl class="account-details"><dt>Name</dt><dd>{{ $customer['name'] }}</dd><dt>Email</dt><dd>{{ $customer['email'] }}</dd></dl>
        <p class="success-notice">{{ $customer['verified'] ? 'Email verified' : 'Email not verified' }}</p>
        @unless ($customer['verified'])<p class="field-help">Verified email is required before accessing earlier guest purchases.</p>@endunless
        <form method="post" action="/account/logout">@csrf<button type="submit" class="primary-button">Sign out</button></form>
    </section>
@endsection
