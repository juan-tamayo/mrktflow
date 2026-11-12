@extends('system.account-layout')
@section('title', 'Privacy requests')
@section('content')
    <p class="eyebrow">Your data</p>
    <h1>Export or delete<span class="accent">.</span></h1>
    <p class="lede">Verified export and deletion requests keep required order and payment records under the store retention policy.</p>
    <form method="post" action="/account/privacy" class="account-card">
        @csrf
        @include('system.account-errors')
        @include('system.account-status')
        <div class="setup-field">
            <label for="privacy-type">Request</label>
            <select id="privacy-type" name="privacy[type]" required>
                <option value="export">Export my account data</option>
                <option value="deletion">Delete my account profile</option>
            </select>
        </div>
        <div class="setup-field">
            <label for="privacy-password">Current password</label>
            <input id="privacy-password" name="privacy[current_password]" type="password" required maxlength="72" autocomplete="current-password">
            <p class="field-help">Required to verify it is you. Deletion removes login and saved addresses. Paid orders, receipts, and payment reconciliation stay on file.</p>
        </div>
        <button type="submit" class="primary-button">Submit verified request</button>
    </form>
    @if ($requests === [])
        <p class="field-help">No privacy requests yet.</p>
    @else
        <ul class="account-details">
            @foreach ($requests as $item)
                <li>
                    {{ $item['type'] }} · {{ $item['status'] }}
                    @if ($item['download'])
                        · <a class="text-link" href="/account/privacy/{{ $item['id'] }}/download">Download CSV</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
    <p class="field-help"><a class="text-link" href="/account">Back to your account</a></p>
@endsection
