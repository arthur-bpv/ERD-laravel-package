<?php

namespace App\Livewire;

use Livewire\Component;

class Organograma extends Component
{
    public array $tree = [
        'nome' => 'Diretor',
        'filhos' => [],
    ];
    
    public function render()
    {
        return view('livewire.organograma');
    }

}
