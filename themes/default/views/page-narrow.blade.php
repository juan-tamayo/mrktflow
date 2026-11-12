@extends('default-theme::layout', [
    'pageKey' => 'home',
    'pageRobots' => 'noindex, nofollow',
    'pageTitle' => 'Page · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css'],
    'mainId' => 'page-main',
    'mainClass' => 'theme-page theme-page-narrow',
    'skipLabel' => 'Skip to page',
    'footerLabel' => 'Content',
    'assignedTemplateKey' => $assignedTemplateKey ?? 'page.narrow',
])
@section('content')
            <p class="eyebrow" data-visual-template="{{ $assignedTemplateKey ?? 'page.narrow' }}">Narrow page</p>
            <h1>{{ $title ?? 'Page' }}<span class="accent">.</span></h1>
@endsection
