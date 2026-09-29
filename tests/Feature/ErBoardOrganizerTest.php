<?php

namespace Tests\Feature;

use App\Livewire\SchemaBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ErBoardOrganizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_toolbar_exposes_the_organizer_action(): void
    {
        $this->get('/schema')
            ->assertOk()
            ->assertSee('wire:click="organizeBoard"', false)
            ->assertSee('Organizar quadro');
    }

    public function test_it_organizes_only_entities_and_keeps_the_classic_relationship_edges(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('organizeBoard')
            ->assertDispatched('flow:fromObject')
            ->assertDispatched('flow:fitView');

        $entities = collect($component->get('entities'))->keyBy('id');
        $nodes = collect($component->instance()->buildNodes());
        $edges = collect($component->instance()->buildEdges());

        $this->assertTrue($entities['posts']['x'] < $entities['users']['x']);
        $this->assertTrue($entities['comments']['x'] > $entities['users']['x']);
        $this->assertNull($nodes->firstWhere('id', 'relation-r1'));

        $relationship = $edges->firstWhere('id', 'r1');
        $this->assertSame(['posts', 'users'], [$relationship['source'], $relationship['target']]);
        $this->assertSame('floating', $relationship['type']);
        $this->assertSame('smoothstep', $relationship['pathType']);
        $this->assertSame('escreve', $relationship['label']);
    }

    public function test_it_preserves_a_self_relationship_offset_while_organizing_its_entity(): void
    {
        $component = Livewire::test(SchemaBoard::class)
            ->call('createSelfRelation', 'comments')
            ->call('onNodeDragEnd', 'relation-r4', ['x' => 400, 'y' => 120]);

        $beforeEntity = collect($component->get('entities'))->firstWhere('id', 'comments');
        $beforeDiamond = collect($component->instance()->buildNodes())->firstWhere('id', 'relation-r4');
        $offset = [
            'x' => $beforeDiamond['position']['x'] - $beforeEntity['x'],
            'y' => $beforeDiamond['position']['y'] - $beforeEntity['y'],
        ];

        $component->call('organizeBoard');

        $afterEntity = collect($component->get('entities'))->firstWhere('id', 'comments');
        $afterDiamond = collect($component->instance()->buildNodes())->firstWhere('id', 'relation-r4');

        $this->assertSame($offset['x'], $afterDiamond['position']['x'] - $afterEntity['x']);
        $this->assertSame($offset['y'], $afterDiamond['position']['y'] - $afterEntity['y']);
    }
}
