<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? config('app.name') }}</title>

    @vite([
        'resources/css/app.css',
        'resources/js/app.js'
    ])

    @livewireStyles
</head>

{{-- Adicionamos h-full aqui para garantir que o body ocupe a altura total do html --}}
<body class="bg-[#0b111e] m-0 p-0 h-full overflow-hidden">
    {{ $slot }}

    @livewireScriptConfig
</body>
</html>