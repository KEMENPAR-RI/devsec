<link rel="manifest" href="{{ route('pwa.manifest') }}">

{{-- Tints the browser/OS chrome; matched to the page background of each theme. --}}
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#040b18" media="(prefers-color-scheme: dark)">

<meta name="application-name" content="{{ app_name() }}">
<meta name="mobile-web-app-capable" content="yes">

{{-- iOS ignores the manifest, so the same three facts are repeated for Safari. --}}
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ app_name() }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="apple-touch-icon" href="{{ route('pwa.icon', 180) }}">
