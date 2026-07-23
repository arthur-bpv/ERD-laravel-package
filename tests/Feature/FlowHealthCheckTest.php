<?php

namespace Tests\Feature;

use Livewire\Livewire;
use Tests\TestCase;

class FlowHealthCheckTest extends TestCase
{
    /** A página /flow-check deve carregar (layout + assets + Livewire). */
    public function test_flow_check_page_loads(): void
    {
        $response = $this->get('/flow-check');

        $response->assertStatus(200);
        $response->assertSee('flow-container', false);       // AlpineFlow renderizou
        $response->assertSee('x-flow-viewport', false);      // diagrama (JS) montado
        // O @vite gera caminhos diferentes conforme o modo: dev server
        // (resources/js/app.js, via Vite rodando) ou build (build/assets/app-*.js).
        // O teste só precisa saber que o app.js entrou no layout.
        $this->assertMatchesRegularExpression(
            '#(resources/js/app\.js|build/assets/app)#',
            $response->getContent(),
        );
    }

    /** Livewire (servidor): increment muda o estado. */
    public function test_livewire_counter_increments(): void
    {
        Livewire::test('flow-health-check')
            ->assertSet('count', 0)
            ->call('increment')
            ->assertSet('count', 1);
    }

    /**
     * Wireflow (ponte servidor → diagrama): addNode despacha os eventos que o
     * AlpineFlow ouve para criar o node pelo seu próprio pipeline (totalmente
     * arrastável). Não mutamos $this->nodes direto — isso só sincronizaria o
     * dado via entangle e o node nasceria sem poder ser arrastado. Em modo
     * :sync o node volta para $this->nodes pelo entangle no cliente.
     */
    public function test_wireflow_server_action_dispatches_add_events(): void
    {
        Livewire::test('flow-health-check')
            ->assertCount('nodes', 2)
            ->assertCount('edges', 1)
            ->call('addNode')
            ->assertDispatched('flow:addNodes')
            ->assertDispatched('flow:connect');
    }
}
