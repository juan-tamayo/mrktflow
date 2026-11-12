@extends('default-theme::layout', [
    'pageKey' => $pageKey ?? 'home',
    'pageRobots' => 'noindex, nofollow',
    'pageTitle' => ($pageTitle ?? 'Content preview').' · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'content-preview-main',
    'mainClass' => 'theme-page',
    'skipLabel' => 'Skip to preview',
    'footerLabel' => 'Content preview',
    'preview' => true,
])
@section('content')
            <aside class="theme-preview-notice" aria-label="Preview status"><strong>Content preview</strong><span>Draft revision · Not live</span></aside>
            <p class="eyebrow">Draft</p>
            <h1>{{ $pageTitle ?? 'Content preview' }}<span class="accent">.</span></h1>
            @isset($excerpt)<p>{{ $excerpt }}</p>@endisset
            @isset($bodyHtml){!! $bodyHtml !!}@endisset
@endsection
