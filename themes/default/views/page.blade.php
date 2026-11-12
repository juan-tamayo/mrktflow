@extends('default-theme::layout', [
    'pageKey' => 'page',
    'pageRobots' => $metadata?->robots ?? 'noindex, nofollow',
    'pageTitle' => ($title ?? 'Page').' · MRKTFlow',
    'pageDescription' => $metadata?->description,
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'page-main',
    'mainClass' => 'theme-page',
    'skipLabel' => 'Skip to page',
    'footerLabel' => 'Content',
    'assignedTemplateKey' => $assignedTemplateKey ?? 'page.default',
    'metadata' => $metadata ?? null,
])
@section('content')
            <p class="eyebrow" data-visual-template="{{ $assignedTemplateKey ?? 'page.default' }}">Page</p>
            <h1>{{ $title ?? 'Page' }}<span class="accent">.</span></h1>
@endsection
