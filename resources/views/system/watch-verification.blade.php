@extends('system.account-layout')
@section('title', 'Confirm your alerts')
@section('content')
    <p class="eyebrow">Stock &amp; price alerts</p>
    @if($done)
        <h1>Your alerts are on<span class="accent">.</span></h1>
        <p class="lede">This email address is confirmed, and the stock and price alerts you asked for are active. Every alert email carries a one-click unsubscribe link — using it needs no sign-in and stops that kind of alert for this address.</p>
    @else
        <h1>Confirm your alerts<span class="accent">.</span></h1>
        <p class="lede">Opening this page changed nothing. Confirm below to verify this email address and turn on the stock and price alerts you subscribed to. This one confirmation is the opt-in — no account needed.</p>
        <form method="post" action="/watch/verify" class="account-card">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button type="submit" class="primary-button">Confirm alerts</button>
        </form>
    @endif
@endsection
