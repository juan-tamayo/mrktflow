@isset($metadata)
    <link rel="canonical" href="{{ $metadata->canonical }}">
    <meta property="og:title" content="{{ $metadata->title }}">
    <meta property="og:type" content="{{ $metadata->ogType }}">
    <meta property="og:url" content="{{ $metadata->canonical }}">
    @if($metadata->description !== '')
        <meta property="og:description" content="{{ $metadata->description }}">
    @endif
    @if($metadata->ogImage !== null)
        <meta property="og:image" content="{{ $metadata->ogImage }}">
    @endif
    <script type="application/ld+json">{!! $metadata->jsonLd() !!}</script>
@endisset
