@extends('system.account-layout')
@section('title', 'Edit your address')
@section('content')
    <p class="eyebrow">Your saved details</p>
    <h1>Edit your address<span class="accent">.</span></h1>
    @include('system.customer-address-form')
    <p class="field-help"><a class="text-link" href="/account/addresses">Back to saved addresses</a></p>
@endsection
