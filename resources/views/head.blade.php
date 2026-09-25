<title>{{ $title }}</title>
@if ($description !== null)
<meta name="description" content="{{ $description }}">
@endif
<meta name="robots" content="{{ $robots->value }}">
@if ($canonical !== null)
<link rel="canonical" href="{{ $canonical }}">
@endif
@foreach ($alternates as $hreflang => $href)
<link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
@endforeach
<meta property="og:site_name" content="{{ $site->name }}">
<meta property="og:type" content="website">
<meta property="og:title" content="{{ $title }}">
@if ($description !== null)
<meta property="og:description" content="{{ $description }}">
@endif
@if ($ogUrl !== null)
<meta property="og:url" content="{{ $ogUrl }}">
@endif
@if ($image !== null)
<meta property="og:image" content="{{ $image }}">
<meta property="og:image:alt" content="{{ $imageAlt }}">
<meta name="twitter:card" content="summary_large_image">
@endif
@if ($verification !== null)
<meta name="google-site-verification" content="{{ $verification }}">
@endif
@foreach ($scripts as $json)
<script type="application/ld+json">{!! $json !!}</script>
@endforeach