@extends('default-theme::layout', [
    'pageKey' => 'preferences',
    'pageTitle' => 'Email preferences · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'preferences-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to preferences',
    'footerLabel' => 'Preferences',
])
@section('content')
            <h1>Email preferences<span class="accent">.</span></h1>
            @if(session('status'))
                <p class="theme-purchase-note">{{ session('status') }}</p>
            @endif
            <p class="theme-purchase-note">Marketing email for {{ $state['email'] }}. Order receipts, shipping notices and download access messages are not marketing and always arrive.</p>
            @if(count($state['suppressions']) > 0)
                <h2>Delivery paused</h2>
                @foreach($state['suppressions'] as $suppression)
                    <p class="theme-purchase-note">{{ ucfirst($suppression['scope']) }} delivery is suppressed ({{ str_replace('_', ' ', $suppression['reason']) }}).</p>
                @endforeach
            @endif
            <form method="post" action="/account/preferences">
                @csrf
                @foreach($labels as $purpose => $label)
                    <p>
                        <label>
                            <input type="checkbox" name="purposes[]" value="{{ $purpose }}" @checked($state['purposes'][$purpose])>
                            {{ ucfirst($label) }} email
                        </label>
                    </p>
                @endforeach
                <button type="submit" class="text-link">Save preferences</button>
            </form>
            <h2>Stop all marketing email</h2>
            <p class="theme-purchase-note">Turns off every purpose above and pauses marketing delivery for this address. You can turn a purpose back on here at any time.</p>
            <form method="post" action="/account/preferences">
                @csrf
                <input type="hidden" name="stop_all" value="1">
                <button type="submit" class="text-link">Stop all marketing email</button>
            </form>
@endsection
