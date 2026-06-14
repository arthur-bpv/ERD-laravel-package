<?php

namespace App\Livewire;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
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
