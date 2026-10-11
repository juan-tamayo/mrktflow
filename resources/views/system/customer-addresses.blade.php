@extends('system.account-layout')
@section('title', 'Saved addresses')
@section('content')
    <p class="eyebrow">Ready for next time</p>
    <h1>Saved addresses<span class="accent">.</span></h1>
    <p class="lede">Keep up to 20 addresses for future checkout. Shipping availability is checked when you place an order.</p>
    @include('system.account-status')
    @forelse($addresses as $saved)
        <section class="account-card" aria-labelledby="address-label-{{ $saved['id'] }}">
            <h2 id="address-label-{{ $saved['id'] }}">{{ $saved['label'] }}</h2>
            <p>{{ $saved['recipient'] }}<br>{{ $saved['line1'] }}@if(!empty($saved['line2']))<br>{{ $saved['line2'] }}@endif<br>{{ $saved['city'] }} {{ $saved['region'] ?? '' }} {{ $saved['postal_code'] ?? '' }}<br>{{ $saved['country_code'] }}</p>
            <p><a class="text-link" href="/account/addresses/{{ $saved['id'] }}">Edit {{ $saved['label'] }}</a></p>
            <form method="post" action="/account/addresses/{{ $saved['id'] }}/delete">@csrf<input type="hidden" name="version" value="{{ $saved['version'] }}"><button type="submit" class="primary-button">Remove {{ $saved['label'] }}</button></form>
        </section>
    @empty<p class="field-help">No saved addresses yet.</p>@endforelse
    <h2>Add an address</h2>
    @include('system.customer-address-form')
    <p class="field-help"><a class="text-link" href="/account">Back to your account</a></p>
@endsection
