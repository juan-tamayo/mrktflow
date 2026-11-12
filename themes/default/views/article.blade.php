@extends('default-theme::layout', [
    'pageKey' => 'article',
    'pageRobots' => 'index, follow',
    'pageTitle' => ($title ?? 'Article').' · MRKTFlow',
    'pageDescription' => $excerpt ?? $metadata?->description,
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'article-main',
    'mainClass' => 'theme-page',
    'skipLabel' => 'Skip to article',
    'footerLabel' => 'Journal',
    'metadata' => $metadata ?? null,
])
@section('content')
            <p class="eyebrow">Journal</p>
            <h1>{{ $title ?? 'Article' }}<span class="accent">.</span></h1>
            @if(($excerpt ?? '') !== '')
                <p class="theme-description">{{ $excerpt }}</p>
            @endif
@endsection
