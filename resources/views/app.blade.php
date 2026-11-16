<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to apply saved or system theme synchronously before first paint --}}
        <script>
            (function() {
                const serverAppearance = '{{ $appearance ?? "system" }}';
                let appearance = serverAppearance;
                try {
                    const stored = window.localStorage.getItem('appearance');
                    if (stored === 'light' || stored === 'dark' || stored === 'system') {
                        appearance = stored;
                    }
                } catch (_e) {}

                const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark);

                document.documentElement.classList.toggle('dark', isDark);
                document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
            })();
        </script>

        {{-- Inline style to set the HTML background color matching semantic canvas tokens in app.css --}}
        <style>
            html {
                background-color: #f4f3f1;
            }

            html.dark {
                background-color: #111216;
            }
        </style>

        <link rel="icon" href="/images/adcom-hero-glow.png" type="image/png">
        <link rel="apple-touch-icon" href="/images/adcom-hero-glow.png">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
