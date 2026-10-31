@extends('system.account-layout')
@section('title', 'Unsubscribe')
@section('content')
    <p class="eyebrow">Email preferences</p>
    @if($done)
        <h1>You are unsubscribed<span class="accent">.</span></h1>
        <p class="lede">You will no longer receive {{ $purposeLabel }} email. Order receipts, shipping notices and download access messages are not marketing and are unaffected. You can re-subscribe any time from your account's preference center.</p>
    @else
        <h1>Unsubscribe<span class="accent">.</span></h1>
        <p class="lede">Opening this page changed nothing. Confirm below to stop {{ $purposeLabel }} email for this address. This one confirmation is all it takes — no sign-in needed.</p>
        <form method="post" action="/preferences/unsubscribe" class="account-card">
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="primary-button">Unsubscribe</button>
        </form>
    @endif
@endsection
