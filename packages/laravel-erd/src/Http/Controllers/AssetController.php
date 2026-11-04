<?php

namespace Makermind\Erd4dev\Http\Controllers;

use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetController
{
    public function show(string $file): BinaryFileResponse
    {
        $files = [
            'erd.js' => [__DIR__.'/../../../dist/erd.js', 'application/javascript'],
            'erd.css' => [__DIR__.'/../../../dist/erd.css', 'text/css'],
            'livewire.js' => [dirname((new \ReflectionClass(Livewire::class))->getFileName(), 2).'/dist/livewire.min.js', 'application/javascript'],
        ];
        foreach (['marketplace-er.json', 'clinic-er.json', 'er-conversion-cases.json'] as $example) {
            $files['examples/'.$example] = [__DIR__.'/../../../resources/examples/'.$example, 'application/json'];
        }
        abort_unless(isset($files[$file]), 404);

        return response()->file($files[$file][0], ['Content-Type' => $files[$file][1]]);
    }
}
