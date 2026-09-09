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

    public function test_relationship_uses_a_normal_arrow_towards_the_referenced_table(): void
    {
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()]);
        $edge = $component->instance()->buildEdges()[0];

        $this->assertSame('N:1', $edge['label']);
        $this->assertArrayNotHasKey('markerStart', $edge);
        $this->assertSame('arrowclosed', $edge['markerEnd']);
        $this->assertSame('floating', $edge['type']);
        $this->assertSame('relational-cardinality', $edge['class']);
        $this->assertStringNotContainsString('→', $edge['label']);
    }

    public function test_recursive_foreign_key_is_a_visible_loop_instead_of_a_collapsed_dot(): void
    {
        $source = Diagram::create([
            'name' => 'Organograma',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [
                'entities' => [[
                    'id' => 'employees',
                    'name' => 'Funcionário',
                    'x' => 120,
                    'y' => 90,
                    'attributes' => [[
                        'id' => 'employees.id',
                        'name' => 'matricula',
                        'type' => 'bigint',
                        'key' => 'PK',
                    ]],
                ]],
                'relations' => [[
                    'id' => 'supervises',
                    'name' => 'Supervisiona',
                    'from' => 'employees',
                    'to' => 'employees',
                    'fromAttr' => '',
                    'toAttr' => 'employees.id',
                    'childCard' => 'cf-zero-many',
                    'parentCard' => 'cf-zero-one',
                    'fromRole' => 'subordinado',
                    'toRole' => 'supervisor',
                ]],
            ],
        ]);
        $relational = Diagram::create([
            'name' => 'Organograma — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $source->id,
            'data' => [],
        ]);

        $component = Livewire::test(RelationalBoard::class, ['diagram' => $relational]);
        $edge = $component->instance()->buildEdges()[0];

        $this->assertSame('employees', $edge['source']);
        $this->assertSame('employees', $edge['target']);
        $this->assertSame('relational-self-loop', $edge['type']);
        $this->assertSame('relation-source-right', $edge['sourceHandle']);
        $this->assertSame('relation-target-top', $edge['targetHandle']);
        $this->assertSame('arrowclosed', $edge['markerEnd']);
        $this->assertSame('Supervisiona · N:1', $edge['label']);
        $component
            ->assertSeeHtml("x-flow-handle:source.right=\"'relation-source-right'\"")
            ->assertSeeHtml("x-flow-handle:target.top=\"'relation-target-top'\"")
            ->assertSee('window.relationalSelfLoopPath', escape: false);
    }

    public function test_recursive_many_to_many_becomes_a_named_table_with_two_visible_fk_routes(): void
    {
        $source = Diagram::create([
            'name' => 'Catálogo',
            'type' => Diagram::TYPE_ENTITY_RELATIONSHIP,
            'data' => [
                'entities' => [[
                    'id' => 'categories',
                    'name' => 'Categoria',
                    'x' => 100,
                    'y' => 100,
                    'attributes' => [[
                        'id' => 'categories.id',
                        'name' => 'categoriaId',
                        'type' => 'bigint',
                        'key' => 'PK',
                    ]],
                ]],
                'relations' => [[
                    'id' => 'contains',
                    'name' => 'Contém',
                    'from' => 'categories',
                    'to' => 'categories',
                    'fromAttr' => 'categories.id',
                    'toAttr' => 'categories.id',
                    'childCard' => 'cf-zero-many',
                    'parentCard' => 'cf-zero-many',
                    'fromRole' => 'continente',
                    'toRole' => 'contida',
                ]],
            ],
        ]);
        $relational = Diagram::create([
            'name' => 'Catálogo — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $source->id,
            'data' => [],
        ]);

        $component = Livewire::test(RelationalBoard::class, ['diagram' => $relational]);
        $table = collect($component->get('tables'))->firstWhere('id', 'relation_contains');
        $edges = collect($component->instance()->buildEdges())
            ->where('source', 'relation_contains')
            ->values();

        $this->assertSame('Contém', $table['name']);
        $this->assertSame(['PK/FK', 'PK/FK'], array_column($table['columns'], 'key'));
        $this->assertCount(2, $edges);
        $this->assertSame(['categories'], $edges->pluck('target')->unique()->values()->all());
        $this->assertSame(['bezier'], $edges->pluck('type')->unique()->values()->all());
        $this->assertCount(2, $edges->pluck('sourceHandle')->unique());
        $this->assertCount(2, $edges->pluck('targetHandle')->unique());
        $this->assertSame(['arrowclosed'], $edges->pluck('markerEnd')->unique()->values()->all());
        $component->assertViewHas(
            'nodes',
            fn (array $nodes) => collect($nodes)->firstWhere('id', 'relation_contains')['data']['name'] === 'Contém',
        );
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

    public function test_relational_edits_are_persisted_without_changing_the_er_source(): void
    {
        $diagram = $this->relationalDiagram();
        $sourceBefore = $diagram->sourceDiagram->data;

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('renameTable', 'staff', 'Funcionarios SQL')
            ->call('renameColumn', 'staff', 'staff.id', 'funcionario_id')
            ->call('addColumn', 'staff', 'criado_em', 'timestamp')
            ->call('toggleColumnNullable', 'staff', 'staff.manual_1')
            ->assertDispatched('flow:fromObject');

        $data = $diagram->fresh()->data;
        $staff = collect($data['tables'])->firstWhere('id', 'staff');
        $manual = collect($staff['columns'])->firstWhere('id', 'staff.manual_1');
        $foreignKey = collect($data['foreignKeys'])->firstWhere('toTable', 'staff');

        $this->assertSame('Funcionarios SQL', $staff['name']);
        $this->assertSame(['funcionario_id'], $staff['primaryKey']);
        $this->assertSame('funcionario_id', $foreignKey['toColumn']);
        $this->assertSame('timestamp', $manual['type']);
        $this->assertTrue($manual['nullable']);
        $this->assertTrue($data['customized']);
        $this->assertSame($sourceBefore, $diagram->sourceDiagram->fresh()->data);
    }

    public function test_relational_editor_removes_derived_columns_and_cascades_invalid_foreign_keys(): void
    {
        $diagram = $this->relationalDiagram();
        $sourceBefore = $diagram->sourceDiagram->data;
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('removeColumn', 'staff', 'staff.id')
            ->assertDispatched('flow:fromObject');

        $staff = collect($component->get('tables'))->firstWhere('id', 'staff');
        $clients = collect($component->get('tables'))->firstWhere('id', 'clients');

        $this->assertNotContains('staff.id', array_column($staff['columns'], 'id'));
        $this->assertSame([], $staff['primaryKey']);
        $this->assertEmpty($component->get('foreignKeys'));
        $this->assertNotContains('staffNo', array_column($clients['columns'], 'name'));
        $this->assertSame($sourceBefore, $diagram->sourceDiagram->fresh()->data);
    }

    public function test_sized_column_types_store_length_precision_and_scale(): void
    {
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->call('addColumn', 'staff', 'codigo', 'varchar')
            ->call('updateColumnSize', 'staff', 'staff.manual_1', 120)
            ->call('addColumn', 'staff', 'valor', 'decimal')
            ->call('updateColumnSize', 'staff', 'staff.manual_2', 12, 4);

        $columns = collect(collect($component->get('tables'))->firstWhere('id', 'staff')['columns']);
        $varchar = $columns->firstWhere('id', 'staff.manual_1');
        $decimal = $columns->firstWhere('id', 'staff.manual_2');

        $this->assertSame(120, $varchar['length']);
        $this->assertNull($varchar['precision']);
        $this->assertSame(12, $decimal['precision']);
        $this->assertSame(4, $decimal['scale']);
        $this->assertNull($decimal['length']);
    }

    public function test_header_links_both_independent_models_and_explains_regeneration(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertSee('Modelo ER')
            ->assertSee('Modelo Relacional')
            ->assertSee('Edição independente')
            ->assertSee('Regenerar do ER');
    }

    public function test_relational_header_exposes_the_shared_black_theme_control(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertSee('window.setErdTheme', escape: false)
            ->assertSee('Ativar tema escuro')
            ->assertSee('Escuro');
    }

    public function test_relational_model_has_an_explicit_save_action(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertSee('Salvar')
            ->call('save')
            ->assertDispatched('relational-saved');
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
