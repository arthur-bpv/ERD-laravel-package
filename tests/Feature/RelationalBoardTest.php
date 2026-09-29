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
        $this->assertSame('smoothstep', $edge['type']);
        $this->assertSame('smoothstep', $edge['pathType']);
        $this->assertSame('relational-cardinality', $edge['class']);
        $this->assertSame('col-clients.staff_no-left', $edge['sourceHandle']);
        $this->assertSame('col-staff.id-right', $edge['targetHandle']);
        $this->assertStringNotContainsString('→', $edge['label']);

        $foreignKey = $component->get('foreignKeys')[0];
        $this->assertSame('clients.staff_no', $foreignKey['fromColumnId']);
        $this->assertSame('staff.id', $foreignKey['toColumnId']);
        $component->assertSeeHtml('class="rel-column relative"');
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
        $this->assertSame('col-employees.supervisor_matricula-right', $edge['sourceHandle']);
        $this->assertSame('col-employees.id-right', $edge['targetHandle']);
        $this->assertSame('arrowclosed', $edge['markerEnd']);
        $this->assertSame('Supervisiona · N:1', $edge['label']);
        $component
            ->assertDontSeeHtml('relation-source-right')
            ->assertDontSeeHtml('relation-target-top')
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
        $this->assertSame(['smoothstep'], $edges->pluck('type')->unique()->values()->all());
        $this->assertCount(2, $edges->pluck('sourceHandle')->unique());
        $this->assertCount(1, $edges->pluck('targetHandle')->unique());
        $this->assertSame(['arrowclosed'], $edges->pluck('markerEnd')->unique()->values()->all());
        $component->assertViewHas(
            'nodes',
            fn (array $nodes) => collect($nodes)->firstWhere('id', 'relation_contains')['data']['name'] === 'Contém',
        );
    }

    public function test_dragging_a_table_persists_its_position_independently(): void
    {
        $diagram = $this->relationalDiagram();

        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('onNodeDragEnd', 'clients', ['x' => 40, 'y' => 600])
            ->assertDispatched('flow:updateNode')
            ->assertDispatched('flow:fromObject');

        $client = collect($diagram->fresh()->data['tables'])->firstWhere('id', 'clients');

        $this->assertSame(40, $client['x']);
        $this->assertSame(600, $client['y']);
        $edge = $component->instance()->buildEdges()[0];
        $this->assertSame('col-clients.staff_no-right', $edge['sourceHandle']);
        $this->assertSame('col-staff.id-right', $edge['targetHandle']);
    }

    public function test_organizing_the_relational_board_centers_the_hub_persists_and_fits_the_view(): void
    {
        $diagram = $this->relationalDiagram();
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('organizeBoard')
            ->assertDispatched('flow:updateNode')
            ->assertDispatched('flow:fromObject')
            ->assertDispatched('flow:fitView')
            ->assertSee('Organizar');

        $tables = collect($component->get('tables'))->keyBy('id');

        $this->assertSame(180, abs($tables['clients']['x'] - $tables['staff']['x']) - 380);
        $this->assertSame($tables->values()->all(), $diagram->fresh()->data['tables']);
    }

    public function test_organizing_a_triangle_keeps_the_outer_relationship_clear_of_the_center_table(): void
    {
        $diagram = $this->relationalDiagram();
        $tables = array_map(fn (string $id): array => [
            'id' => $id,
            'name' => $id,
            'kind' => 'entity',
            'x' => 0,
            'y' => 0,
            'columns' => [],
            'primaryKey' => [],
        ], ['middle', 'left', 'right']);
        $foreignKeys = array_map(fn (array $pair, int $index): array => [
            'id' => 'fk-'.$index,
            'fromTable' => $pair[0],
            'toTable' => $pair[1],
            'fromColumn' => 'id',
            'toColumn' => 'id',
        ], [['middle', 'left'], ['middle', 'right'], ['left', 'right']], [0, 1, 2]);
        $diagram->update(['data' => ['tables' => $tables, 'foreignKeys' => $foreignKeys]]);

        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('organizeBoard');
        $organized = collect($component->get('tables'))->keyBy('id');

        $this->assertSame($organized['left']['y'], $organized['right']['y']);
        $this->assertGreaterThanOrEqual(
            $organized['left']['y'] + 62 + 140,
            $organized['middle']['y'],
        );
        $this->assertSame($organized->values()->all(), $diagram->fresh()->data['tables']);
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

    public function test_nullable_edit_updates_the_existing_canvas_node_without_replacing_it(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->call('addColumn', 'staff', 'note')
            ->call('toggleColumnNullable', 'staff', 'staff.manual_1')
            ->assertDispatched('flow:updateNode', fn (string $event, array $params): bool => $params['id'] === 'staff'
                && collect($params['changes']['data']['columns'])->firstWhere('id', 'staff.manual_1')['nullable'] === true,
            )
            ->assertDispatched('flow:fromObject', fn (string $event, array $params): bool => ! array_key_exists('nodes', $params['data']) && array_key_exists('edges', $params['data']),
            );
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

    public function test_database_dialect_selector_exposes_supported_relational_databases(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertSet('dialect', 'mysql')
            ->assertSee('Banco')
            ->assertSee('MySQL')
            ->assertSee('PostgreSQL')
            ->assertSee('Oracle')
            ->assertSee('SQL Server');
    }

    public function test_changing_database_dialect_converts_types_clamps_sizes_and_persists_the_choice(): void
    {
        $diagram = $this->relationalDiagram();
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('addColumn', 'staff', 'contador', 'tinyint')
            ->call('addColumn', 'staff', 'codigo_externo', 'varchar')
            ->call('updateColumnSize', 'staff', 'staff.manual_2', 8000)
            ->call('setDialect', 'oracle')
            ->assertSet('dialect', 'oracle')
            ->assertDispatched('relational-saved');

        $columns = collect(collect($component->get('tables'))->firstWhere('id', 'staff')['columns']);

        $this->assertSame('smallint', $columns->firstWhere('id', 'staff.manual_1')['type']);
        $this->assertSame(4000, $columns->firstWhere('id', 'staff.manual_2')['length']);
        $this->assertSame('oracle', $diagram->fresh()->data['dialect']);

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram->fresh()])
            ->assertSet('dialect', 'oracle');
    }

    public function test_invalid_database_dialect_and_unavailable_column_type_are_rejected(): void
    {
        $diagram = $this->relationalDiagram();
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('setDialect', 'sqlite')
            ->assertSet('dialect', 'mysql')
            ->call('updateColumnType', 'staff', 'staff.id', 'money');

        $staff = collect($component->get('tables'))->firstWhere('id', 'staff');

        $this->assertSame('bigint', collect($staff['columns'])->firstWhere('id', 'staff.id')['type']);
        $this->assertArrayNotHasKey('dialect', $diagram->fresh()->data);
    }

    public function test_header_links_both_independent_models_and_explains_regeneration(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertSee('aria-label="Modelos do projeto"', escape: false)
            ->assertSee('Modelo Relacional')
            ->assertSee('Mais')
            ->assertSee('Regenerar do ER');
    }

    public function test_relational_header_exposes_the_shared_black_theme_control(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertSee('window.setErdTheme', escape: false)
            ->assertSee('Ativar tema escuro')
            ->assertSee('Tema escuro');
    }

    public function test_relational_model_has_an_explicit_save_action(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertSee('Salvar')
            ->call('save')
            ->assertDispatched('relational-saved');
    }

    public function test_sql_preview_uses_the_saved_relational_edits_and_current_dialect(): void
    {
        $diagram = $this->relationalDiagram();
        $sourceBefore = $diagram->sourceDiagram->data;

        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('renameTable', 'staff', 'Equipe')
            ->call('renameColumn', 'staff', 'staff.id', 'employee_id')
            ->call('addColumn', 'staff', 'salary', 'decimal')
            ->call('updateColumnSize', 'staff', 'staff.manual_1', 12, 2)
            ->call('setDialect', 'pgsql')
            ->call('openSqlPreview')
            ->assertSet('showSql', true)
            ->assertSet('sqlError', null)
            ->assertSee('Baixar .sql');

        $sql = $component->get('sqlPreview');
        $this->assertStringContainsString('CREATE TABLE "Equipe"', $sql);
        $this->assertStringContainsString('"employee_id" BIGINT NOT NULL', $sql);
        $this->assertStringContainsString('"salary" NUMERIC(12, 2) NOT NULL', $sql);
        $this->assertStringContainsString('REFERENCES "Equipe" ("employee_id")', $sql);
        $this->assertSame('pgsql', $diagram->fresh()->data['dialect']);
        $this->assertSame($sourceBefore, $diagram->sourceDiagram->fresh()->data);

        $component->call('downloadSql')->assertFileDownloaded('modelo-relacional-pgsql.sql');
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
            ->assertDispatched('flow:updateNode')
            ->assertDispatched('flow:fitView')
            ->assertDispatched('relational-regenerated');

        $staff = collect($diagram->fresh()->data['tables'])->firstWhere('id', 'staff');
        $this->assertContains('email', array_column($staff['columns'], 'name'));
    }

    public function test_regeneration_replaces_canvas_tables_without_navigation(): void
    {
        $diagram = $this->relationalDiagram();
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram]);
        $source = $diagram->sourceDiagram;
        $data = $source->data;
        $data['entities'] = [$data['entities'][0], [
            'id' => 'departments', 'name' => 'Departments', 'x' => 500, 'y' => 100,
            'attributes' => [['id' => 'departments.id', 'name' => 'id', 'type' => 'bigint', 'key' => 'PK']],
        ]];
        $data['relations'] = [];
        $source->update(['data' => $data]);

        $component->call('regenerate')
            ->assertDispatched('flow:removeNodes', ids: ['clients'])
            ->assertDispatched('flow:addNodes', fn (string $event, array $params): bool => collect($params['nodes'])->contains('id', 'departments'),
            )
            ->assertDispatched('relational-regenerated');

        $this->assertSame(['staff', 'departments'], array_column($component->get('tables'), 'id'));
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
