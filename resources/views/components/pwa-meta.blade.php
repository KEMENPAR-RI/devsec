{{--
    Everything the browser reads to decide the app is installable, shared by both
    layouts - the prompt has to be available on the login screen too, since that
    is the first page most people ever see.

    The manifest and its icons come from App\Http\Controllers\PwaController, so
    they follow the name and logo saved in Pengaturan instead of a static file
    that would go stale the moment the branding changes.
--}}
<link rel="manifest" href="{{ route('pwa.manifest') }}">

{{-- Tints the browser/OS chrome; matched to the page background of each theme. --}}
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#09090b" media="(prefers-color-scheme: dark)">

<meta name="application-name" content="{{ config('app.name') }}">
<meta name="mobile-web-app-capable" content="yes">

{{-- iOS ignores the manifest, so the same three facts are repeated for Safari. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ route('pwa.icon', 180) }}">
