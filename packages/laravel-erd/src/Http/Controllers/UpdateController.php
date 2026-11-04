<?php

namespace Makermind\Erd4dev\Http\Controllers;

use Livewire\Mechanisms\HandleRequests\HandleRequests;

class UpdateController
{
    public function __invoke(HandleRequests $handler)
    {
        // Mark only this request as a Livewire update, without registering a
        // global custom endpoint that would replace the host application's one.
        $route = request()->route();
        $action = $route->getAction();
        $route->setAction(array_replace($action, ['as' => 'erd.livewire.update']));

        try {
            return $handler->handleUpdate();
        } finally {
            $route->setAction($action);
        }
    }
}
