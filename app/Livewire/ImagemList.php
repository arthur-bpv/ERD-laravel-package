<?php

namespace App\Livewire;

use Livewire\Component;

class ImagemList extends Component
{
    public $perPage = 2;

    public function render()
    {
        $imagens = array();
        for ($i = 0; $i < $this->perPage; $i++) {
            $imagens[] = 'https://picsum.photos/600/800?random='.rand(1,200);
        }

        return view('livewire.imagem-list', [
            'imagens' => $imagens
        ]);
    }
    public function loadMore()
    {
        $this->perPage += 2;
    }
}