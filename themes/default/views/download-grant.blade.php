@extends('default-theme::layout', [
    'pageKey' => 'download-grant',
    'pageTitle' => 'Download · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'download-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to download',
    'footerLabel' => 'Downloads',
])
@section('content')
            <h1>{{ $heading }}<span class="accent">.</span></h1>
            <p>{{ $status['label'] }} · {{ $status['title'] }} · {{ $status['sku'] }} · version {{ $status['version'] }}</p>
            <p class="theme-purchase-note">{{ $status['downloads_remaining'] }} of {{ $status['download_limit'] }} downloads remaining for this purchase.</p>
            @if($status['state'] === 'issued')
                @include('storefront.slots.download-start')
            @elseif($status['state'] === 'consumed' && $status['transfer'] !== null)
                @if($status['transfer']['state'] === 'active')
                    <p class="theme-purchase-note">Your download is started. You can download or resume it until {{ $status['transfer']['expires_at'] }} without spending another download.</p>
                    <p><a class="text-link" href="{{ $stream_url }}">Download file</a></p>
                @elseif($status['transfer']['state'] === 'revoked')
                    <p class="theme-purchase-note">This download was revoked and can no longer be streamed.</p>
                @else
                    <p class="theme-purchase-note">This download window has expired. Request a new download from your downloads area if you still have downloads remaining.</p>
                @endif
            @elseif($status['state'] === 'expired')
                <p class="theme-purchase-note">This start link expired before the download began; nothing was spent. Request a new download from your downloads area.</p>
            @else
                <p class="theme-purchase-note">This download grant was revoked and cannot be started.</p>
            @endif
            <p><a class="text-link" href="{{ $back_url }}">Back to downloads</a></p>
@endsection
