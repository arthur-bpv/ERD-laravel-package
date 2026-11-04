<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">

        <title>{{ $title ?? config('app.name') }}</title>

        <script>
            function applyErdTheme() {
                const saved = localStorage.getItem('erd-theme');
                const theme = saved ?? (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                document.documentElement.classList.toggle('dark', theme === 'dark');
            }

            // Apply the theme on initial load.
            applyErdTheme();

            // Reapply it after wire:navigate changes the page.
            document.addEventListener('livewire:navigated', applyErdTheme);

            window.setErdTheme = (theme) => {
                document.documentElement.classList.toggle('dark', theme === 'dark');
                localStorage.setItem('erd-theme', theme);
                window.dispatchEvent(new CustomEvent('erd-theme-changed', { detail: { theme } }));
            };

        </script>

        @if (config('erd.development_assets'))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @else
            <link rel="stylesheet" href="{{ route('erd.assets', ['file' => 'erd.css']) }}">
            <script src="{{ route('erd.assets', ['file' => 'erd.js']) }}"></script>
        @endif

        @livewireStyles
    </head>
    <body>
        {{ $slot }}

        @if (config('erd.development_assets'))
            {!! str_replace(
                json_encode(url(app('livewire')->getUpdateUri())),
                json_encode(route('erd.update')),
                \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scriptConfig()
            ) !!}
        @else
            {!! str_replace(
                'data-update-uri="'.url(app('livewire')->getUpdateUri()).'"',
                'data-update-uri="'.route('erd.update').'"',
                \Livewire\Mechanisms\FrontendAssets\FrontendAssets::scripts(['url' => route('erd.assets', ['file' => 'livewire.js'])])
            ) !!}
        @endif
    </body>
</html>
