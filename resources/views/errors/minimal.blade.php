@php
    $cookieAppearance = request()->cookie('appearance', 'system');
    $isExplicitDark = $cookieAppearance === 'dark';
    $isSystem = $cookieAppearance !== 'light' && $cookieAppearance !== 'dark';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="{{ $isExplicitDark ? 'dark' : '' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>@yield('title') — {{ config('app.name', 'AD Com') }}</title>
        <script>
            (function() {
                var appearance = '{{ $isExplicitDark ? "dark" : ($isSystem ? "system" : "light") }}';
                try {
                    var stored = window.localStorage.getItem('appearance');
                    if (stored === 'light' || stored === 'dark' || stored === 'system') {
                        appearance = stored;
                    }
                } catch (_e) {}
                var prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                var dark = appearance === 'dark' || (appearance === 'system' && prefersDark);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            })();
        </script>
        <style>
            :root {
                --err-bg: #f4f3f1;
                --err-card: #ffffff;
                --err-border: rgba(27, 25, 29, 0.1);
                --err-fg: #1b191d;
                --err-muted: #5c5862;
                --err-accent: #a9253b;
                --err-accent-bg: rgba(169, 37, 59, 0.1);
                --err-shadow: 0 20px 50px rgba(27, 25, 29, 0.08);
            }
            html.dark {
                --err-bg: #111216;
                --err-card: #181a20;
                --err-border: rgba(255, 255, 255, 0.1);
                --err-fg: #f4f4f6;
                --err-muted: #a4a7b3;
                --err-accent: #fb7185;
                --err-accent-bg: rgba(224, 72, 94, 0.16);
                --err-shadow: 0 24px 60px rgba(0, 0, 0, 0.48);
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100svh;
                display: grid;
                place-items: center;
                padding: 1.5rem;
                background: var(--err-bg);
                color: var(--err-fg);
                font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
                -webkit-font-smoothing: antialiased;
            }
            .panel {
                width: 100%;
                max-width: 30rem;
                background: var(--err-card);
                border: 1px solid var(--err-border);
                border-radius: 1.5rem;
                padding: 2rem;
                box-shadow: var(--err-shadow);
            }
            .code {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.35rem 0.75rem;
                border-radius: 9999px;
                background: var(--err-accent-bg);
                color: var(--err-accent);
                font-size: 0.75rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }
            h1 {
                margin: 1rem 0 0.5rem;
                font-size: 1.375rem;
                line-height: 1.3;
                font-weight: 700;
                letter-spacing: -0.02em;
            }
            p {
                margin: 0;
                font-size: 0.9rem;
                line-height: 1.6;
                color: var(--err-muted);
            }
            .actions {
                margin-top: 1.5rem;
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
            }
            a.btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                height: 2.5rem;
                padding: 0 1.125rem;
                border-radius: 9999px;
                font-size: 0.8125rem;
                font-weight: 600;
                text-decoration: none;
                border: 1px solid var(--err-border);
                color: var(--err-fg);
                background: transparent;
            }
            a.btn-primary {
                border-color: transparent;
                background: #a9253b;
                color: #ffffff;
            }
        </style>
    </head>
    <body>
        <main class="panel">
            <span class="code">AD Com · @yield('code')</span>
            <h1>@yield('title')</h1>
            <p>@yield('message')</p>
            <div class="actions">
                <a href="{{ url('/') }}" class="btn btn-primary">Kembali ke beranda</a>
                <a href="{{ url()->previous() }}" class="btn">Halaman sebelumnya</a>
            </div>
        </main>
    </body>
</html>
