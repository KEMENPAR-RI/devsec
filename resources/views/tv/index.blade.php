<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TV Monitoring - {{ $appName ?? 'Security App' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex h-full flex-col overflow-hidden bg-white font-sans text-zinc-900 antialiased">

    <header class="flex items-center justify-between px-8 pt-4">
        <p class="text-sm text-zinc-500">Diperbarui: <span id="diperbarui">-</span></p>
        <p id="slide-info" class="text-sm text-zinc-400"></p>
    </header>

    <div id="notif" class="fixed right-6 top-6 z-50 hidden w-96 items-start gap-3 rounded-2xl border px-5 py-4 shadow-lg transition-opacity duration-500">
        <span id="notif-lamp" class="relative mt-1 flex h-3 w-3 shrink-0">
            <span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"></span>
            <span class="relative inline-flex h-3 w-3 rounded-full"></span>
        </span>
        <div class="min-w-0">
            <p id="notif-title" class="truncate text-sm font-semibold"></p>
            <p id="notif-msg" class="truncate text-xs"></p>
        </div>
    </div>

    <main class="min-h-0 flex-1 p-6">
        <div id="grid" class="grid h-full grid-cols-4 grid-rows-4 gap-4"></div>
    </main>

    <script>
        const TOKEN = @js($token);
        const PER_SLIDE = 16;
        let kartu = [];
        let slideIndex = 0;
        let slideTimer = null;
        let lastEventAt = null;
        let notifTimer = null;

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function siteCard(site) {
            const response = site.response_time_ms === null || site.response_time_ms === undefined
                ? '-'
                : `${site.response_time_ms} ms`;
            const code = site.status_code ? `HTTP ${site.status_code}` : (site.error_message || '-');
            const subtitle = site.kind === 'insiden' && site.incident_title
                ? site.incident_title
                : site.url;

            return `
                <div class="flex min-h-0 flex-col justify-between rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                    <div class="flex items-center gap-2">
                        <span class="h-3 w-3 shrink-0 rounded-full ${escapeHtml(site.dot)}"></span>
                        <p class="truncate text-sm font-semibold text-zinc-900">${escapeHtml(site.name)}</p>
                    </div>
                    <p class="truncate text-xs text-zinc-500">${escapeHtml(subtitle)}</p>
                    <div class="flex items-end justify-between">
                        <p class="text-xs font-medium text-zinc-600">${escapeHtml(site.status_label)}</p>
                        <p class="text-[11px] text-zinc-400">${escapeHtml(site.occurred_at)}</p>
                    </div>
                    <p class="truncate text-[11px] text-zinc-400">${escapeHtml(code)} · ${escapeHtml(response)}</p>
                </div>
            `;
        }

        function renderSlide() {
            const totalSlides = Math.max(1, Math.ceil(kartu.length / PER_SLIDE));
            if (slideIndex >= totalSlides) slideIndex = 0;

            const start = slideIndex * PER_SLIDE;
            const items = kartu.slice(start, start + PER_SLIDE);

            const grid = document.getElementById('grid');
            while (grid.children.length < PER_SLIDE) {
                const empty = document.createElement('div');
                empty.className = 'rounded-2xl border border-dashed border-zinc-100';
                grid.appendChild(empty);
            }

            Array.from(grid.children).forEach((el, i) => {
                if (i < items.length) {
                    el.outerHTML = siteCard(items[i]);
                }
            });

            // Replace remaining cells with dashed placeholders to keep grid shape.
            Array.from(grid.children).forEach((el, i) => {
                if (i >= items.length) {
                    el.outerHTML = '<div class="rounded-2xl border border-dashed border-zinc-100"></div>';
                }
            });

            document.getElementById('slide-info').textContent = `Slide ${slideIndex + 1} / ${totalSlides}`;
        }

        function scheduleSlideshow() {
            if (slideTimer) clearInterval(slideTimer);
            slideTimer = setInterval(() => {
                slideIndex = (slideIndex + 1) % Math.max(1, Math.ceil(kartu.length / PER_SLIDE));
                renderSlide();
            }, 30000);
        }

        function showNotif(event) {
            const notif = document.getElementById('notif');
            const title = document.getElementById('notif-title');
            const msg = document.getElementById('notif-msg');
            const lamp = document.getElementById('notif-lamp');

            const isInsiden = event.pesan && event.status !== 'offline' && event.status !== 'unknown';
            const colors = event.status === 'offline' || event.status === 'unknown'
                ? { border: 'border-amber-300', bg: 'bg-amber-50', text: 'text-amber-800', lamp: 'bg-amber-500', ping: 'bg-amber-400' }
                : event.status === 'up'
                    ? { border: 'border-emerald-300', bg: 'bg-emerald-50', text: 'text-emerald-800', lamp: 'bg-emerald-500', ping: 'bg-emerald-400' }
                    : { border: 'border-red-300', bg: 'bg-red-50', text: 'text-red-800', lamp: 'bg-red-500', ping: 'bg-red-400' };

            notif.className = `fixed right-6 top-6 z-50 flex w-96 items-start gap-3 rounded-2xl border px-5 py-4 shadow-lg transition-opacity duration-500 ${colors.border} ${colors.bg}`;
            lamp.querySelectorAll('span').forEach((s, i) => {
                s.className = i === 0
                    ? `absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 ${colors.ping}`
                    : `relative inline-flex h-3 w-3 rounded-full animate-pulse ${colors.lamp}`;
            });
            title.className = `truncate text-sm font-semibold ${colors.text}`;
            msg.className = `truncate text-xs ${colors.text}`;
            title.textContent = event.name;
            msg.textContent = event.pesan;
            notif.classList.remove('hidden', 'opacity-0');

            if (notifTimer) clearTimeout(notifTimer);
            notifTimer = setTimeout(() => {
                notif.classList.add('opacity-0');
                setTimeout(() => notif.classList.add('hidden'), 500);
            }, 10000);
        }

        async function refresh() {
            try {
                const res = await fetch('/tv/data?token=' + encodeURIComponent(TOKEN), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                document.getElementById('diperbarui').textContent = data.diperbarui;

                const newEvent = data.latest_event_at && data.latest_event_at !== lastEventAt;
                kartu = data.kartu || [];

                if (newEvent) {
                    lastEventAt = data.latest_event_at;
                    slideIndex = 0;
                    scheduleSlideshow();
                    if (data.latest_event) showNotif(data.latest_event);
                }

                renderSlide();
            } catch (e) {
                // TV keeps its last known cards on a transient network blip.
            }
        }

        refresh();
        scheduleSlideshow();
        setInterval(refresh, 300000);
    </script>
</body>
</html>
