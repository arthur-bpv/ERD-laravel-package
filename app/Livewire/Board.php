<?php

namespace App\Livewire;

use Livewire\Component;

class Board extends Component
{
    public array $nodes = [
        [
            'id' => '1',
            'position' => ['x' => 0, 'y' => 0],
            'data' => ['label' => 'Input',],
        ],
        [
            'id' => '2',
            'position' => ['x' => 250, 'y' => 120],
            'data' => ['label' => 'Process'],
        ]
    ];

    public array $edges = [
        ['id' => 'e1-2', 'source' => '1', 'target' => '2']
    ];

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.board');
    }
}
