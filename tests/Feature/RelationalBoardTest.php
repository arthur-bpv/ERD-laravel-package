<?php

namespace Tests\Feature;

use App\Livewire\RelationalBoard;
use App\Models\Diagram;
use App\Services\ErToRelationalTransformer;
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

    public function test_background_control_uses_artisanflow_patterns(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertSee('Alterar fundo do modelo relacional')
            ->assertSee('Pontilhado')
            ->assertSee('Liso')
            ->assertSee('Grade')
            ->assertSee('Cruzes')
            ->assertSee('patchConfig({ background: pattern })', escape: false);
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

        $this->assertSame(80, abs($tables['clients']['x'] - $tables['staff']['x']) - 380);
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
            $organized['left']['y'] + 74 + 80,
            $organized['middle']['y'],
        );
        $this->assertSame($organized->values()->all(), $diagram->fresh()->data['tables']);
    }

    public function test_organizing_a_chain_wraps_tables_into_compact_rows_for_export(): void
    {
        $diagram = $this->relationalDiagram();
        $tables = array_map(fn (int $index): array => [
            'id' => 'table-'.$index,
            'name' => 'Table '.$index,
            'kind' => 'entity',
            'x' => 0,
            'y' => 0,
            'columns' => [],
            'primaryKey' => [],
        ], range(0, 7));
        $foreignKeys = array_map(fn (int $index): array => [
            'id' => 'fk-'.$index,
            'fromTable' => 'table-'.$index,
            'toTable' => 'table-'.($index + 1),
            'fromColumn' => 'id',
            'toColumn' => 'id',
        ], range(0, 6));
        $diagram->update(['data' => ['tables' => $tables, 'foreignKeys' => $foreignKeys]]);

        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('organizeBoard');
        $organized = collect($component->get('tables'));
        $xs = $organized->pluck('x')->unique();
        $ys = $organized->pluck('y')->unique();

        $this->assertLessThanOrEqual(3, $xs->count());
        $this->assertGreaterThanOrEqual(3, $ys->count());
        $this->assertLessThan(1400, $xs->max() - $xs->min() + 380);
        $this->assertSame($organized->all(), $diagram->fresh()->data['tables']);
    }

    public function test_relational_edits_are_persisted_without_changing_the_er_source(): void
    {
        $diagram = $this->relationalDiagram();
        $sourceBefore = $diagram->sourceDiagram->data;

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('toggleColumnNullable', 'clients', 'clients.staff_no')
            ->assertDispatched('flow:fromObject');

        $data = $diagram->fresh()->data;
        $staff = collect($data['tables'])->firstWhere('id', 'staff');
        $client = collect($data['tables'])->firstWhere('id', 'clients');
        $foreignKey = collect($data['foreignKeys'])->firstWhere('toTable', 'staff');

        $this->assertSame('Staff', $staff['name']);
        $this->assertSame(['staffNo'], $staff['primaryKey']);
        $this->assertSame('staffNo', $foreignKey['toColumn']);
        $this->assertTrue(collect($client['columns'])->firstWhere('id', 'clients.staff_no')['nullable']);
        $this->assertTrue($data['customized']);
        $this->assertSame($sourceBefore, $diagram->sourceDiagram->fresh()->data);
    }

    public function test_relational_table_and_column_names_are_read_only(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->assertDontSee('renameTable')
            ->assertDontSee('renameColumn')
            ->assertDontSee('Clique para renomear a tabela');

        $this->assertFalse(method_exists(RelationalBoard::class, 'renameTable'));
        $this->assertFalse(method_exists(RelationalBoard::class, 'renameColumn'));
    }

    public function test_nullable_edit_updates_the_existing_canvas_node_without_replacing_it(): void
    {
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->call('toggleColumnNullable', 'clients', 'clients.staff_no')
            ->assertDispatched('flow:updateNode')
            ->assertDispatched('flow:fromObject', fn (string $event, array $params): bool => ! array_key_exists('nodes', $params['data']) && array_key_exists('edges', $params['data']),
            );

        $clients = collect($component->get('tables'))->firstWhere('id', 'clients');
        $this->assertTrue(collect($clients['columns'])->firstWhere('id', 'clients.staff_no')['nullable']);
    }

    public function test_sized_column_types_store_length_precision_and_scale(): void
    {
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->call('updateColumnType', 'staff', 'staff.id', 'varchar')
            ->call('updateColumnSize', 'staff', 'staff.id', 120)
            ->call('updateColumnType', 'clients', 'clients.id', 'decimal')
            ->call('updateColumnSize', 'clients', 'clients.id', 12, 4);

        $tables = collect($component->get('tables'));
        $varchar = collect($tables->firstWhere('id', 'staff')['columns'])->firstWhere('id', 'staff.id');
        $decimal = collect($tables->firstWhere('id', 'clients')['columns'])->firstWhere('id', 'clients.id');

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
            ->call('updateColumnType', 'staff', 'staff.id', 'tinyint')
            ->call('updateColumnType', 'clients', 'clients.id', 'varchar')
            ->call('updateColumnSize', 'clients', 'clients.id', 8000)
            ->call('setDialect', 'oracle')
            ->assertSet('dialect', 'oracle')
            ->assertDispatched('relational-saved');

        $tables = collect($component->get('tables'));

        $this->assertSame('smallint', collect($tables->firstWhere('id', 'staff')['columns'])->firstWhere('id', 'staff.id')['type']);
        $this->assertSame(4000, collect($tables->firstWhere('id', 'clients')['columns'])->firstWhere('id', 'clients.id')['length']);
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

    public function test_sql_preview_uses_generated_names_and_current_dialect(): void
    {
        $diagram = $this->relationalDiagram();
        $sourceBefore = $diagram->sourceDiagram->data;

        $component = Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('setDialect', 'pgsql')
            ->call('openSqlPreview')
            ->assertSet('showSql', true)
            ->assertSet('sqlError', null)
            ->assertSee('x-ref="sqlBox"', escape: false)
            ->assertSee('window.copyBoardText(this.$refs.sqlBox.textContent)', escape: false)
            ->assertSee('Baixar .sql');

        $sql = $component->get('sqlPreview');
        $this->assertStringContainsString('CREATE TABLE "Staff"', $sql);
        $this->assertStringContainsString('"staffNo" BIGINT NOT NULL', $sql);
        $this->assertStringContainsString('REFERENCES "Staff" ("staffNo")', $sql);
        $this->assertSame('pgsql', $diagram->fresh()->data['dialect']);
        $this->assertSame($sourceBefore, $diagram->sourceDiagram->fresh()->data);

        $component->call('downloadSql')->assertFileDownloaded('modelo-relacional-pgsql.sql');
    }

    public function test_json_modal_copies_and_downloads_the_relational_model(): void
    {
        Livewire::test(RelationalBoard::class, ['diagram' => $this->relationalDiagram()])
            ->call('toggleJson')
            ->assertSet('showJson', true)
            // Mesmo componente do quadro ER, mesmo rodapé.
            ->assertSeeHtml('class="rel-modal"')
            ->assertSeeHtml('class="rel-modal-actions"')
            ->assertSee('Copiar')
            ->assertSee('Baixar .json')
            ->assertSee('wire:click="downloadJson"', escape: false)
            ->call('downloadJson')
            ->assertFileDownloaded('modelo-relacional.json');
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

    public function test_a_generated_board_is_not_flagged_as_outdated(): void
    {
        $diagram = $this->generatedRelationalDiagram();

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertViewHas('isOutdated', false)
            ->assertViewHas('drift', [])
            ->assertDontSee('Este modelo Relacional está desatualizado');
    }

    public function test_editing_the_er_only_warns_and_never_regenerates_the_relational_copy(): void
    {
        $diagram = $this->generatedRelationalDiagram();
        $this->addEmailToStaff($diagram->sourceDiagram);

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertViewHas('isOutdated', true)
            ->assertSee('Este modelo Relacional está desatualizado')
            ->assertSee('Nada foi regravado')
            ->assertSee('Regenerar do ER')
            // O texto já vem escapado da Blade, daí o escape: false.
            ->assertSee('Coluna &quot;email&quot; foi adicionada em &quot;Staff&quot;.', escape: false);

        // O aviso não pode gravar nada: a cópia continua sem a coluna nova.
        $staff = collect($diagram->fresh()->data['tables'])->firstWhere('id', 'staff');
        $this->assertNotContains('email', array_column($staff['columns'], 'name'));
    }

    public function test_regenerating_an_outdated_board_applies_the_er_and_clears_the_warning(): void
    {
        $diagram = $this->generatedRelationalDiagram();
        $this->addEmailToStaff($diagram->sourceDiagram);

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertViewHas('isOutdated', true)
            ->call('regenerate')
            ->assertViewHas('isOutdated', false)
            ->assertViewHas('drift', [])
            ->assertDontSee('Este modelo Relacional está desatualizado');

        $staff = collect($diagram->fresh()->data['tables'])->firstWhere('id', 'staff');
        $this->assertContains('email', array_column($staff['columns'], 'name'));
    }

    public function test_editing_the_relational_copy_alone_never_raises_the_warning(): void
    {
        $diagram = $this->generatedRelationalDiagram();

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->call('updateColumnType', 'staff', 'staff.id', 'varchar')
            ->call('onNodeDragEnd', 'staff', ['x' => 900, 'y' => 40])
            ->assertViewHas('isOutdated', false)
            ->assertDontSee('Este modelo Relacional está desatualizado');
    }

    public function test_the_outdated_warning_reminds_that_manual_edits_will_be_replaced(): void
    {
        $diagram = $this->generatedRelationalDiagram();
        $this->addEmailToStaff($diagram->sourceDiagram);

        Livewire::test(RelationalBoard::class, ['diagram' => $diagram])
            ->assertViewHas('isOutdated', true)
            ->assertDontSee('As edições manuais deste quadro também serão substituídas.')
            ->call('updateColumnType', 'staff', 'staff.id', 'varchar')
            ->assertSee('As edições manuais deste quadro também serão substituídas.');
    }

    public function test_node_height_accounts_for_column_rows(): void
    {
        $component = Livewire::test(RelationalBoard::class, ['diagram' => $this->generatedRelationalDiagram()]);

        $nodes = collect($component->instance()->buildNodes())->keyBy('id');

        $staff = collect($component->get('tables'))->firstWhere('id', 'staff');
        $this->assertSame(74 + count($staff['columns']) * 44, $nodes['staff']['dimensions']['height']);
    }

    public function test_the_column_editor_has_no_add_or_remove_controls_and_edges_cannot_be_reconnected(): void
    {
        $this->assertFalse(method_exists(RelationalBoard::class, 'addColumn'));
        $this->assertFalse(method_exists(RelationalBoard::class, 'removeColumn'));

        Livewire::test(RelationalBoard::class, ['diagram' => $this->generatedRelationalDiagram()])
            ->assertDontSeeHtml('$wire.addColumn(')
            ->assertDontSeeHtml('$wire.removeColumn(')
            ->assertSeeHtml('edgesReconnectable');
    }

    private function addEmailToStaff(Diagram $source): void
    {
        $data = $source->data;
        $data['entities'][0]['attributes'][] = [
            'id' => 'staff.email',
            'name' => 'email',
            'type' => 'varchar',
            'key' => 'UQ',
        ];
        $source->update(['data' => $data]);
    }

    private function generatedRelationalDiagram(): Diagram
    {
        $source = $this->sourceDiagram();

        return Diagram::create([
            'name' => 'CRM — Relacional',
            'type' => Diagram::TYPE_RELATIONAL,
            'source_diagram_id' => $source->id,
            'data' => app(ErToRelationalTransformer::class)->transform($source->data),
        ]);
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
