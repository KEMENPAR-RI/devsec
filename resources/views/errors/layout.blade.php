{{--
    Shared shell for every error page.

    Deliberately self-contained: no @vite, no Livewire, no Blade components from
    the rest of the app. A 500 is often thrown *because* something in that chain
    is broken - a missing manifest after a failed build, a provider that throws on
    boot - and an error page that depends on the machinery that just failed is an
    error page that renders as a second stack trace. Everything here is inline,
    and the only external file is the PWA icon that is already on disk.

    The glassmorphism is done with layered translucent surfaces over blurred
    colour orbs, which is what makes the effect read as depth rather than as a
    flat grey panel: the orbs must be visible *through* the card. backdrop-filter
    is the one piece a browser may not support - in that case the card falls back
    to its own solid background and the page still reads correctly.

    Each error view sets $code / $title / $message and may @section('mark-glyph')
    an extra path drawn inside the shield.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $code }} - {{ $title }}</title>
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#f4f7fb" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#040b18" media="(prefers-color-scheme: dark)">
    <link rel="icon" href="/pwa/icon-192.png">

    <style>
        :root {
            color-scheme: light dark;

            /* Product navy (#132B50) and warm gold (#EAC170), same values as
               resources/css/app.css - repeated rather than imported because this
               page must not depend on the Vite build. */
            --navy: #132b50;
            --navy-500: #3063b0;
            --navy-300: #91b0de;
            --gold: #eac170;

            --bg: #f4f7fb;
            --text: #071224;
            --muted: #55617a;

            /* The glass itself. On light backgrounds the surface is a white veil;
               the border is what sells the edge, so it stays brighter than the
               fill. */
            --glass-bg: rgba(255, 255, 255, 0.55);
            --glass-border: rgba(255, 255, 255, 0.85);
            --glass-shadow: 0 20px 60px -20px rgba(7, 18, 36, 0.28);

            --chip-bg: rgba(19, 43, 80, 0.06);
            --chip-border: rgba(19, 43, 80, 0.12);

            --orb-1: rgba(48, 99, 176, 0.42);
            --orb-2: rgba(234, 193, 112, 0.32);
            --orb-3: rgba(19, 43, 80, 0.30);

            --grid-line: rgba(19, 43, 80, 0.07);
            --watermark: rgba(19, 43, 80, 0.06);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #040b18;
                --text: #f1f5fb;
                --muted: #98a6c0;

                /* Dark glass is a *lighter* veil over a darker ground - the same
                   trick inverted. Pure black here would kill the blur effect. */
                --glass-bg: rgba(255, 255, 255, 0.06);
                --glass-border: rgba(255, 255, 255, 0.14);
                --glass-shadow: 0 24px 70px -20px rgba(0, 0, 0, 0.75);

                --chip-bg: rgba(255, 255, 255, 0.06);
                --chip-border: rgba(255, 255, 255, 0.12);

                --orb-1: rgba(48, 99, 176, 0.50);
                --orb-2: rgba(234, 193, 112, 0.22);
                --orb-3: rgba(110, 150, 212, 0.28);

                --grid-line: rgba(255, 255, 255, 0.05);
                --watermark: rgba(255, 255, 255, 0.05);
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            position: relative;
            overflow-x: hidden;
            background: var(--bg);
            color: var(--text);
            font-family: 'Instrument Sans', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /* --- Backdrop: grid + orbs. Everything here is decorative, hence
               aria-hidden on the markup and pointer-events:none. --- */

        .backdrop {
            position: fixed;
            inset: 0;
            pointer-events: none;
            overflow: hidden;
        }

        /* A faint engineering grid. Reads as "instrumentation" without becoming a
           texture you actually look at. */
        .grid {
            position: absolute;
            inset: -2px;
            background-image:
                linear-gradient(var(--grid-line) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
            background-size: 56px 56px;
            /* Fades the grid out at the edges so it does not end in a hard line. */
            mask-image: radial-gradient(ellipse 90% 70% at 50% 45%, #000 40%, transparent 100%);
            -webkit-mask-image: radial-gradient(ellipse 90% 70% at 50% 45%, #000 40%, transparent 100%);
        }

        .orb {
            position: absolute;
            border-radius: 9999px;
            filter: blur(72px);
        }

        .orb-1 { width: 30rem; height: 30rem; top: -9rem; left: -7rem; background: var(--orb-1); }
        .orb-2 { width: 26rem; height: 26rem; bottom: -8rem; right: -5rem; background: var(--orb-2); }
        .orb-3 { width: 22rem; height: 22rem; top: 45%; left: 55%; background: var(--orb-3); }

        /* --- The card --- */

        .card {
            position: relative;
            width: 100%;
            max-width: 30rem;
            padding: 2.5rem 2rem;
            text-align: center;
            border-radius: 1.5rem;
            border: 1px solid var(--glass-border);
            background: var(--glass-bg);
            box-shadow: var(--glass-shadow);
            /* The actual glass. Without support the background above still stands
               on its own, just without the frosting. */
            backdrop-filter: blur(20px) saturate(160%);
            -webkit-backdrop-filter: blur(20px) saturate(160%);
        }

        /* The status code, sitting behind the content as a watermark rather than
           as a headline - the message matters more than the number. */
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 12rem;
            font-weight: 700;
            line-height: 1;
            letter-spacing: -0.05em;
            color: var(--watermark);
            user-select: none;
            pointer-events: none;
            z-index: 0;
        }

        .content { position: relative; z-index: 1; }

        .mark {
            width: 4rem;
            height: 4rem;
            margin: 0 auto 1.5rem;
            border-radius: 1.125rem;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, var(--navy-500), var(--navy));
            box-shadow: 0 10px 30px -8px rgba(19, 43, 80, 0.55);
        }

        @media (prefers-color-scheme: dark) {
            .mark {
                background: linear-gradient(145deg, var(--navy-500), var(--navy));
                box-shadow: 0 10px 30px -8px rgba(0, 0, 0, 0.7);
            }
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.3rem 0.7rem;
            margin-bottom: 1rem;
            border-radius: 9999px;
            border: 1px solid var(--chip-border);
            background: var(--chip-bg);
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .chip-dot {
            width: 0.4rem;
            height: 0.4rem;
            border-radius: 9999px;
            background: var(--gold);
        }

        h1 {
            margin: 0 0 0.625rem;
            font-size: 1.5rem;
            font-weight: 600;
            letter-spacing: -0.02em;
        }

        .message {
            margin: 0 auto;
            max-width: 24rem;
            font-size: 0.9375rem;
            line-height: 1.65;
            color: var(--muted);
        }

        .actions {
            margin-top: 1.75rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.625rem;
            justify-content: center;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.625rem 1.25rem;
            border-radius: 0.75rem;
            border: 1px solid transparent;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.15s ease, opacity 0.15s ease;
        }

        .btn:hover { transform: translateY(-1px); opacity: 0.92; }
        .btn:active { transform: translateY(0); }

        .btn-primary {
            background: var(--navy);
            color: #ffffff;
        }

        @media (prefers-color-scheme: dark) {
            .btn-primary { background: var(--navy-500); }
        }

        .btn-ghost {
            background: transparent;
            border-color: var(--chip-border);
            color: var(--text);
        }

        .btn-ghost:hover { background: var(--chip-bg); }

        .footnote {
            margin: 1.5rem 0 0;
            font-size: 0.75rem;
            color: var(--muted);
            opacity: 0.85;
        }

        /* An animated backdrop is decoration; anyone who has asked the OS to stop
           motion should not get it. The orbs are static regardless - only the
           entrance is dropped here. */
        @media (prefers-reduced-motion: no-preference) {
            .card {
                animation: rise 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
            }

            @keyframes rise {
                from { opacity: 0; transform: translateY(12px); }
                to   { opacity: 1; transform: translateY(0); }
            }
        }

        @media (max-width: 30rem) {
            .card { padding: 2rem 1.25rem; }
            .watermark { font-size: 8rem; }
            h1 { font-size: 1.25rem; }
            .btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="backdrop" aria-hidden="true">
        <div class="grid"></div>
        <div class="orb orb-1"></div>
        <div class="orb orb-2"></div>
        <div class="orb orb-3"></div>
    </div>

    <main class="card">
        {{-- Decorative: the code is already in <title> and announced in the
             heading region below, so a screen reader should skip these digits. --}}
        <div class="watermark" aria-hidden="true">{{ $code }}</div>

        <div class="content">
            <div class="mark" aria-hidden="true">
                {{-- Shield, matching the mark on public/offline.html so the two
                     standalone pages look like the same product. --}}
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#ffffff"
                     stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3l7 3v6c0 4.2-2.9 7.6-7 9-4.1-1.4-7-4.8-7-9V6l7-3z" />
                    @yield('mark-glyph')
                </svg>
            </div>

            <div class="chip">
                <span class="chip-dot"></span>
                Error {{ $code }}
            </div>

            <h1>{{ $title }}</h1>
            <p class="message">{{ $message }}</p>

            <div class="actions">
                {{-- A literal "/" rather than url('/'): errors/503.blade.php is
                     rendered while the app is in maintenance mode, before the
                     request is fully bootstrapped, and a root-relative path needs
                     no container at all. It also cannot inherit a wrong APP_URL. --}}
                <a class="btn btn-primary" href="/">Kembali ke beranda</a>
                <button class="btn btn-ghost" type="button" onclick="history.back()">Halaman sebelumnya</button>
            </div>

            @isset($footnote)
                <p class="footnote">{{ $footnote }}</p>
            @endisset
        </div>
    </main>
</body>
</html>
