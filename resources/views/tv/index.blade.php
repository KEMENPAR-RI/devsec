<!DOCTYPE html>
<html lang="id" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>TV Monitoring - {{ $appName ?? 'Security App' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full flex-col bg-white font-sans text-zinc-900 antialiased">

    <header class="flex items-center justify-between px-8 pt-6">
        <p class="text-sm text-zinc-500">Diperbarui: <span id="diperbarui">-</span></p>
    </header>

    <main class="grid flex-1 grid-cols-2 grid-rows-2 gap-6 p-8">
        <section class="flex min-h-0 flex-col rounded-3xl border border-zinc-200 bg-zinc-50 p-6">
            <div class="mb-4 flex items-end justify-between">
                <div>
                    <p class="text-lg text-zinc-500">Total Situs</p>
                    <p id="total" class="font-mono text-6xl font-semibold text-accent-600">-</p>
                </div>
            </div>
            <div id="list-total" class="min-h-0 flex-1 space-y-3 overflow-y-auto"></div>
        </section>

        <section class="flex min-h-0 flex-col rounded-3xl border border-zinc-200 bg-zinc-50 p-6">
            <div class="mb-4 flex items-end justify-between">
                <div>
                    <p class="text-lg text-zinc-500">Online</p>
                    <p id="online" class="font-mono text-6xl font-semibold text-emerald-600">-</p>
                </div>
            </div>
            <div id="list-online" class="min-h-0 flex-1 space-y-3 overflow-y-auto"></div>
        </section>

        <section class="flex min-h-0 flex-col rounded-3xl border border-zinc-200 bg-zinc-50 p-6">
            <div class="mb-4 flex items-end justify-between">
                <div>
                    <p class="text-lg text-zinc-500">Down</p>
                    <p id="down" class="font-mono text-6xl font-semibold text-red-600">-</p>
                </div>
            </div>
            <div id="list-down" class="min-h-0 flex-1 space-y-3 overflow-y-auto"></div>
        </section>

        <section class="flex min-h-0 flex-col rounded-3xl border border-zinc-200 bg-zinc-50 p-6">
            <div class="mb-4 flex items-end justify-between">
                <div>
                    <p class="text-lg text-zinc-500">Insiden Aktif</p>
                    <p id="insiden" class="font-mono text-6xl font-semibold text-amber-600">-</p>
                </div>
            </div>
            <div id="list-insiden" class="min-h-0 flex-1 space-y-3 overflow-y-auto"></div>
        </section>
    </main>

    <script>
        const TOKEN = @js($token);

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function siteRow(site) {
            const response = site.response_time_ms === null || site.response_time_ms === undefined
                ? '-'
                : `${site.response_time_ms} ms`;
            const checked = site.last_checked_at || 'belum dicek';
            const code = site.status_code ? `HTTP ${site.status_code}` : (site.error_message || '-');

            return `
                <div class="flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white px-4 py-3">
                    <span class="h-3 w-3 shrink-0 rounded-full ${escapeHtml(site.dot)}"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-zinc-900">${escapeHtml(site.name)}</p>
                        <p class="truncate text-xs text-zinc-500">${escapeHtml(site.url)}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-xs font-medium text-zinc-700">${escapeHtml(site.status_label)}</p>
                        <p class="text-[11px] text-zinc-500">${checked} · ${escapeHtml(response)} · ${escapeHtml(code)}</p>
                    </div>
                </div>
            `;
        }

        function incidentRow(incident) {
            const detected = incident.detected_at || '-';

            return `
                <div class="flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white px-4 py-3">
                    <span class="h-3 w-3 shrink-0 rounded-full ${escapeHtml(incident.dot)}"></span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-zinc-900">${escapeHtml(incident.title)}</p>
                        <p class="truncate text-xs text-zinc-500">${escapeHtml(incident.website_name)} · ${escapeHtml(incident.website_url)}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-xs font-medium text-amber-700">${escapeHtml(incident.severity)}</p>
                        <p class="text-[11px] text-zinc-500">${escapeHtml(incident.status_label)} · ${escapeHtml(detected)}</p>
                    </div>
                </div>
            `;
        }

        function renderList(id, items, rowRenderer, emptyText) {
            const el = document.getElementById(id);
            if (!items || !items.length) {
                el.innerHTML = `<p class="text-sm text-zinc-500">${escapeHtml(emptyText)}</p>`;
                return;
            }

            el.innerHTML = items.map(rowRenderer).join('');
        }

        async function refresh() {
            try {
                const res = await fetch('/tv/data?token=' + encodeURIComponent(TOKEN), { headers: { 'Accept': 'application/json' } });
                if (!res.ok) return;
                const data = await res.json();
                document.getElementById('total').textContent = data.total;
                document.getElementById('online').textContent = data.online;
                document.getElementById('down').textContent = data.down;
                document.getElementById('insiden').textContent = data.insiden;
                document.getElementById('diperbarui').textContent = data.diperbarui;

                renderList('list-total', data.semua_situs, siteRow, 'Belum ada situs yang dipantau.');
                renderList('list-online', data.situs_online, siteRow, 'Tidak ada situs online.');
                renderList('list-down', data.situs_down, siteRow, 'Tidak ada situs down.');
                renderList('list-insiden', data.insiden_aktif, incidentRow, 'Tidak ada insiden aktif.');
            } catch (e) {
                // TV keeps its last known numbers on a transient network blip.
            }
        }

        refresh();
        setInterval(refresh, 60000);
    </script>
</body>
</html>
