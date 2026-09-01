<?php

namespace Tests\Feature;

use App\Livewire\RelationalBoard;
use App\Models\Diagram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RelationalBoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_renders_generated_tables_and_foreign_keys(): void
    {
        $diagram = $this->relationalDiagram();

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertSee('Client')
            ->assertSee('staffNo')
            ->assertSee('FK');
    }

    public function test_relationship_is_expressed_by_cardinality_on_the_line_without_a_diamond(): void
    {
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()]);
        $edge = $component->instance()->buildEdges()[0];

        $this->assertSame('N:1', $edge['label']);
        $this->assertSame('cf-zero-many', $edge['markerStart']['type']);
        $this->assertSame('cf-one-one', $edge['markerEnd']['type']);
        $this->assertSame('relational-cardinality', $edge['class']);
        $this->assertStringNotContainsString('→', $edge['label']);
    }

    public function test_dragging_a_table_persists_its_position_independently(): void
    {
        $diagram = $this->relationalDiagram();

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('onNodeDragEnd', 'clients', ['x' => 410.4, 'y' => 220.7]);

        $client = collect($diagram->fresh()->data['tables'])->firstWhere('id', 'clients');

        $this->assertSame(410, $client['x']);
        $this->assertSame(221, $client['y']);
    }

    public function test_regeneration_explicitly_replaces_the_logical_copy_from_er(): void
    {
        $diagram = $this->relationalDiagram();
        $source = $diagram->sourceDiagram;
        $data = $source->data;
        $data['entities'][0]['attributes'][] = [
            'id' => 'staff.email',
            'name' => 'email',
            'type' => 'varchar',
            'key' => 'UQ',
        ];
        $source->update(['data' => $data]);

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('regenerate')
            ->assertRedirect();

        $staff = collect($diagram->fresh()->data['tables'])->firstWhere('id', 'staff');
        $this->assertContains('email', array_column($staff['columns'], 'name'));
    }

    public function test_er_diagram_cannot_be_opened_as_a_relational_board(): void
    {
        $er = $this->sourceDiagram();

        Livewire::test(RelationalBoard::class, ['diagram' => $er])
            ->assertNotFound();
    }

    private function relationalDiagram(): Diagram
    {
        $source = $this->sourceDiagram();

        return Diagram::create([
            'name' => 'CRM — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $source->id,
            'data' => [],
        ]);
    }

    private function sourceDiagram(): Diagram
    {
        return Diagram::create([
            'name' => 'CRM',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [
                'entities' => [
                    [
                        'id' => 'staff',
                        'name' => 'Staff',
                        'x' => 40,
                        'y' => 40,
                        'attributes' => [['id' => 'staff.id', 'name' => 'staffNo', 'type' => 'bigint', 'key' => 'PK']],
                    ],
                    [
                        'id' => 'clients',
                        'name' => 'Client',
                        'x' => 420,
                        'y' => 180,
                        'attributes' => [['id' => 'clients.id', 'name' => 'clientNo', 'type' => 'bigint', 'key' => 'PK']],
                    ],
                ],
                'relations' => [[
                    'id' => 'registers',
                    'name' => 'Registers',
                    'from' => 'clients',
                    'fromAttr' => '',
                    'to' => 'staff',
                    'toAttr' => 'staff.id',
                    'childCard' => 'cf-zero-many',
                    'parentCard' => 'cf-one-one',
                ]],
            ],
        ]);
    }
}
