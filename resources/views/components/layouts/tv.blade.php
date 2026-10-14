{{-- Standalone wallboard layout: no sidebar, no auth chrome. --}}
<!DOCTYPE html>
<html lang="id" class="h-full bg-accent-800">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TV Monitoring - {{ $appName ?? 'Security App' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="flex h-full flex-col overflow-hidden bg-accent-800 bg-linear-to-br from-accent-700 via-accent-800 to-accent-900 font-sans text-accent-50 antialiased">
    {{ $slot }}
    @livewireScripts
</body>
</html>
