<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }}</title>

        <script>
            (() => {
                const saved = localStorage.getItem('erd-theme');
                const theme = saved ?? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.classList.toggle('dark', theme === 'dark');
            })();

            window.setErdTheme = (theme) => {
                document.documentElement.classList.toggle('dark', theme === 'dark');
                localStorage.setItem('erd-theme', theme);
                window.dispatchEvent(new CustomEvent('erd-theme-changed', { detail: { theme } }));
            };
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])

        @livewireStyles
    </head>
    <body>
        {{ $slot }}

        @livewireScriptConfig
    </body>
</html>
